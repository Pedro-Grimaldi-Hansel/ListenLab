<?php
declare(strict_types=1);

namespace ListenLab\Storage;

use ListenLab\AppError;
use ListenLab\Config;
use ListenLab\Database;
use PDO;

final class RawBackup
{
    private array $approved=[];
    public function __construct(private readonly PDO $db, private readonly Config $config, private readonly RawStore $store) {}

    /** The caller holds the ingestion advisory lock throughout backup and maintenance. */
    public function create(): array
    {
        if (!$this->store->archiveRoot || $this->db->inTransaction()) throw new AppError('Backup exige arquivo configurado e conexão fora de uma transação.');
        $name = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(8));
        $directory = $this->store->archiveRoot . '/snapshots/' . $name;
        if (!mkdir($directory,0700,true)) throw new AppError('Não foi possível criar o snapshot privado.');
        $this->db->beginTransaction();
        try {
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $snapshot = $this->db->query('SELECT pg_export_snapshot()')->fetchColumn();
            $schema = $this->db->query('SELECT current_schema()')->fetchColumn();
            $database = $this->db->query('SELECT current_database()')->fetchColumn();
            $tables = $this->fingerprints($this->db,$schema);
            $objects = [];
            $rows = $this->db->query('SELECT path,sha256,size_bytes FROM raw_artifacts ORDER BY id');
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                // Verify each receipt/legacy file, not just one file per hash.
                $body = $this->store->readVerified($row['path'],$row['sha256']);
                if (!isset($objects[$row['sha256']])) {
                    $this->store->backupObject($body);
                    $objects[$row['sha256']] = strlen($body);
                }
            }
            PgTools::run($this->config,'pg_dump',['--dbname',$database,'--schema',$schema,'--format=custom','--no-owner','--no-acl','--snapshot',$snapshot,'--file',$directory . '/database.dump']);
            $manifest = ['format'=>1,'snapshot'=>$name,'created_at'=>gmdate(DATE_ATOM),'schema'=>$schema,'database'=>$database,
                'dump_sha256'=>hash_file('sha256',$directory . '/database.dump'),'objects'=>$objects,'tables'=>$tables];
            $this->store->publish($directory . '/manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT) . "\n");
            $this->db->commit();
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
        $this->verify($name);
        return ['snapshot'=>$name,'objects'=>count($objects),'verified'=>true];
    }

    public function verify(string $name): array
    {
        [$directory,$manifest] = $this->manifest($name);
        foreach ($manifest['objects'] as $hash=>$size) $this->store->verifyBackupObject($hash);
        if (!hash_equals($manifest['dump_sha256'],hash_file('sha256',$directory . '/database.dump'))) throw new AppError('Hash do dump de backup não confere.');
        $temporaryDatabase = 'listenlab_restore_' . bin2hex(random_bytes(8)) . '_test';
        $mainDatabase = $this->db->query('SELECT current_database()')->fetchColumn();
        if ($temporaryDatabase === $mainDatabase || !preg_match('/^listenlab_restore_[0-9a-f]{16}_test$/D',$temporaryDatabase)) throw new AppError('Nome de banco de restauração inseguro.');
        $created = false;
        try {
            $this->db->exec('CREATE DATABASE "' . $temporaryDatabase . '"'); $created = true;
            PgTools::run($this->config,'pg_restore',['--dbname',$temporaryDatabase,'--clean','--if-exists','--exit-on-error','--no-owner','--no-acl',$directory . '/database.dump']);
            $restored = Database::connect($this->config,$temporaryDatabase);
            $actual = $this->fingerprints($restored,$manifest['schema']);
            if ($actual !== $manifest['tables']) throw new AppError('A restauração não corresponde ao banco e índice do snapshot.');
            $restored = null;
        } finally {
            if ($created) $this->db->exec('DROP DATABASE "' . $temporaryDatabase . '" WITH (FORCE)');
        }
        $verification = ['verified_at'=>gmdate(DATE_ATOM),'manifest_sha256'=>hash_file('sha256',$directory . '/manifest.json'),
            'database_restored'=>true,'objects_verified'=>count($manifest['objects'])];
        $verificationFile = $directory . '/verified-' . bin2hex(random_bytes(8)) . '.json';
        $this->store->publish($verificationFile,json_encode($verification,JSON_THROW_ON_ERROR) . "\n");
        return $verification;
    }

    public function assertCovers(string $name, string $hash): void
    {
        if(isset($this->approved[$name])){
            if(!isset($this->approved[$name]['objects'][$hash]))throw new AppError('Conteúdo ausente do snapshot verificado.');
            $this->store->verifyBackupObject($hash);return;
        }
        [$directory,$manifest] = $this->manifest($name);
        if (!array_key_exists($hash,$manifest['objects'])) throw new AppError('O conteúdo não pertence ao snapshot verificado.');
        $manifestHash = hash_file('sha256',$directory . '/manifest.json');
        $verified = false;
        foreach (glob($directory . '/verified-*.json') as $file) {
            $record = json_decode(file_get_contents($file),true);
            if (($record['manifest_sha256']??null) === $manifestHash && ($record['database_restored']??false) === true) $verified=true;
        }
        if (!$verified || !hash_equals($manifest['dump_sha256'],hash_file('sha256',$directory . '/database.dump'))) throw new AppError('Snapshot não verificado ou dump alterado; RAW local preservado.');
        $this->store->verifyBackupObject($hash);
        $this->approved[$name]=$manifest;
    }

    /** Only this application's generated, verified snapshots are rotated. RAW objects never expire. */
    public function rotate(string $current,int $keep): void
    {
        if($keep===0)return;
        if($keep<2)throw new AppError('Mantenha pelo menos dois snapshots verificados.');
        [$currentDirectory,$new]=$this->manifest($current);$candidates=[];
        foreach(glob($this->store->archiveRoot.'/snapshots/*',GLOB_ONLYDIR) as $directory){
            $name=basename($directory);
            if($name===$current || is_link($directory) || !preg_match('/^[0-9]{8}T[0-9]{6}Z-[0-9a-f]{16}$/D',$name))continue;
            try{[, $old]=$this->manifest($name);}catch(\Throwable){continue;}
            if(($old['database']??null)!==($new['database']??null) || $old['schema']!==$new['schema'] || array_diff_key($old['objects'],$new['objects']))continue;
            $files=glob($directory.'/*');$verified=false;$safe=true;
            foreach($files as $file){
                $base=basename($file);
                if(!is_file($file)||is_link($file)||!($base==='database.dump'||$base==='manifest.json'||preg_match('/^verified-[0-9a-f]{16}\.json$/D',$base))){$safe=false;break;}
                if(str_starts_with($base,'verified-')){
                    $record=json_decode(file_get_contents($file),true);
                    if(($record['manifest_sha256']??null)===hash_file('sha256',$directory.'/manifest.json')&&($record['database_restored']??false)===true)$verified=true;
                }
            }
            if($safe&&$verified)$candidates[$name]=['directory'=>$directory,'files'=>$files];
        }
        krsort($candidates);$position=1;$root=realpath($this->store->archiveRoot.'/snapshots');
        foreach($candidates as $entry){
            if(++$position<=$keep)continue;
            $resolved=realpath($entry['directory']);
            if(!$root||!$resolved||!str_starts_with($resolved,$root.DIRECTORY_SEPARATOR))throw new AppError('Caminho inseguro ao rotacionar snapshots.');
            // No recursive deletion, and no user dumps or unknown files are touched.
            foreach($entry['files'] as $file)if(!unlink($file))throw new AppError('Não foi possível rotacionar um snapshot antigo.');
            if(!rmdir($entry['directory']))throw new AppError('Não foi possível concluir a rotação do snapshot.');
        }
    }

    /** Restore receipts to a NEW directory. Database restore is always tested separately. */
    public function restoreRaw(string $name, string $target): int
    {
        [$directory,$manifest] = $this->manifest($name);
        if (file_exists($target)) throw new AppError('A pasta de restauração precisa ser nova.');
        $this->verify($name);
        if (!mkdir($target,0700,true)) throw new AppError('Não foi possível criar a pasta de restauração.');
        // Restore the index in an isolated database to regenerate every logical path.
        $temporaryDatabase = 'listenlab_restore_' . bin2hex(random_bytes(8)) . '_test';
        $created = false; $count=0;
        try {
            $this->db->exec('CREATE DATABASE "' . $temporaryDatabase . '"'); $created=true;
            PgTools::run($this->config,'pg_restore',['--dbname',$temporaryDatabase,'--clean','--if-exists','--exit-on-error','--no-owner','--no-acl',$directory . '/database.dump']);
            $restored=Database::connect($this->config,$temporaryDatabase);
            $schema=$this->identifier($manifest['schema']);
            $store=new RawStore($target,$this->store->archiveRoot);
            foreach($restored->query('SELECT path,sha256,size_bytes FROM '.$schema.'.raw_artifacts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row){
                if(str_contains($row['path'],'..') || str_starts_with($row['path'],'/') || str_contains($row['path'],'\\'))throw new AppError('Caminho inseguro no índice do backup.');
                $store->publish($target.'/'.$row['path'],json_encode(['listenlab_raw_reference'=>1,'sha256'=>$row['sha256'],'size_bytes'=>(int)$row['size_bytes']],JSON_THROW_ON_ERROR)."\n");
                $store->readVerified($row['path'],$row['sha256']);++$count;
            }
            $restored=null;
        }finally{if($created)$this->db->exec('DROP DATABASE "'.$temporaryDatabase.'" WITH (FORCE)');}
        return $count;
    }

    private function manifest(string $name): array
    {
        if (!$this->store->archiveRoot || !preg_match('/^[0-9]{8}T[0-9]{6}Z-[0-9a-f]{16}$/D',$name)) throw new AppError('Snapshot de backup inválido.');
        $directory=$this->store->archiveRoot.'/snapshots/'.$name;
        if(!is_file($directory.'/manifest.json'))throw new AppError('Manifesto de backup não encontrado.');
        $manifest=json_decode(file_get_contents($directory.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
        if(($manifest['format']??null)!==1 || ($manifest['snapshot']??null)!==$name || !is_array($manifest['objects']??null) || !is_array($manifest['tables']??null))throw new AppError('Manifesto de backup incompatível.');
        return [$directory,$manifest];
    }

    private function fingerprints(PDO $db,string $schema): array
    {
        $tables=Database::query($db,'SELECT tablename FROM pg_tables WHERE schemaname=? ORDER BY tablename',[$schema])->fetchAll(PDO::FETCH_COLUMN);
        $result=[];
        foreach($tables as $table){
            $context=hash_init('sha256');$count=0;
            $rows=$db->query('SELECT md5(row_to_json(t)::text) FROM '.$this->identifier($schema).'.'.$this->identifier($table).' t ORDER BY 1');
            while(($digest=$rows->fetchColumn())!==false){hash_update($context,$digest."\n");++$count;}
            $result[$table]=['rows'=>$count,'sha256'=>hash_final($context)];
        }
        return $result;
    }

    private function identifier(string $value): string
    {
        return '"'.str_replace('"','""',$value).'"';
    }
}

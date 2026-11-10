<?php
declare(strict_types=1);

namespace ListenLab\Storage;

use ListenLab\AppError;
use ListenLab\Config;
use ListenLab\Database;
use PDO;

final class RawMaintenance
{
    public function __construct(private readonly PDO $db,private readonly Config $config,private readonly RawStore $store){}

    public function run(bool $apply=false,bool $archive=false,int $olderThanDays=30,bool $ifDue=false): array
    {
        if($olderThanDays<0 || $olderThanDays>36500)throw new AppError('Retenção deve estar entre 0 e 36500 dias.');
        if(!$this->db->query('SELECT pg_try_advisory_lock(194701,2)')->fetchColumn())throw new AppError('Outra ingestão ou manutenção está em execução.');
        $runId=null;
        try{
            // Failed restores retain their files for diagnosis; do not create another
            // full dump at every collection. A manual run can retry immediately.
            if($ifDue && $this->db->query("SELECT EXISTS(SELECT 1 FROM raw_storage_runs WHERE started_at>now()-interval '24 hours')")->fetchColumn())return ['skipped'=>true,'reason'=>'checked_today'];
            $index=new RawIndex($this->db,$this->store);$before=$index->stats();
            $rows=$this->db->query('SELECT id,path,sha256,size_bytes,received_at FROM raw_artifacts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $legacy=[];$objects=[];$cold=[];$cutoff=microtime(true)-$olderThanDays*86400;
            foreach($rows as $row){
                $body=$this->store->readVerified($row['path'],$row['sha256']);
                if(!$this->store->isReference($row['path'],$row['sha256']))$legacy[]=$row['id'];
                $objects[$row['sha256']]=true;
                if(strtotime($row['received_at'])<=$cutoff)$cold[$row['sha256']]=true;
            }
            $plan=['dry_run'=>!$apply,'legacy_artifacts'=>count($legacy),'unique_contents'=>count($objects),
                'archive_candidates'=>$archive?count($cold):0,'local_bytes_before'=>$before['local_bytes']];
            if(!$apply)return $plan;
            $runId=(int)Database::query($this->db,"INSERT INTO raw_storage_runs(operation,status,local_bytes_before)VALUES(?,'running',?)RETURNING id",[$archive?'archive':'compact',$before['local_bytes']])->fetchColumn();
            $snapshot=null;$backup=null;
            $hasLocalCandidates=$archive && (bool)Database::query($this->db,"SELECT EXISTS(SELECT 1 FROM raw_objects WHERE tier='local' AND first_received_at<=to_timestamp(?))",[$cutoff])->fetchColumn();
            if($legacy || $hasLocalCandidates || ($ifDue && $rows)){
                $backup=new RawBackup($this->db,$this->config,$this->store);
                $snapshot=$backup->create()['snapshot'];
            }
            foreach($rows as $row){
                if(in_array($row['id'],$legacy,true)){
                    $backup->assertCovers($snapshot,$row['sha256']);
                    $this->store->replaceLegacy($row['path'],$row['sha256']);
                }
                $index->register((int)$row['id'],$row['path'],$row['sha256'],(int)$row['size_bytes'],$row['received_at']);
            }
            $evicted=0;
            if($archive && $snapshot!==null){
                foreach(Database::query($this->db,"SELECT sha256 FROM raw_objects WHERE tier='local' AND first_received_at<=to_timestamp(?)",[$cutoff])->fetchAll(PDO::FETCH_COLUMN) as $hash){
                    $backup->assertCovers($snapshot,$hash);$this->store->evictLocal($hash);
                    $info=$this->store->objectInfo($hash);
                    Database::query($this->db,"UPDATE raw_objects SET tier='archive',encoding=?,stored_bytes=?,updated_at=now() WHERE sha256=?",[$info['encoding'],$info['stored_bytes'],$hash]);++$evicted;
                }
            }
            foreach($rows as $row)$this->store->readVerified($row['path'],$row['sha256']);
            $after=$index->stats();
            if($snapshot!==null)$backup->rotate($snapshot,$this->config->integer('RAW_BACKUP_KEEP',3,0,50));
            $archiveBytes=0;$snapshots=0;
            if($this->store->archiveRoot && is_dir($this->store->archiveRoot)){
                foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->store->archiveRoot,\FilesystemIterator::SKIP_DOTS)) as $file)if($file->isFile())$archiveBytes+=$file->getSize();
                $snapshots=count(array_unique(array_map('dirname',glob($this->store->archiveRoot.'/snapshots/*/verified-*.json'))));
            }
            Database::query($this->db,"UPDATE raw_storage_runs SET status='completed',finished_at=now(),artifacts=?,objects=?,local_bytes_after=?,backup_verified=?,snapshot_name=?,archive_bytes=?,snapshot_count=? WHERE id=?",
                [count($rows),count($objects),$after['local_bytes'],$snapshot!==null,$snapshot,$archiveBytes,$snapshots,$runId]);
            return $plan+['run_id'=>$runId,'snapshot'=>$snapshot,'backup_verified'=>$snapshot!==null,'archived_contents'=>$evicted,'local_bytes_after'=>$after['local_bytes'],'saved_local_bytes'=>max(0,$before['local_bytes']-$after['local_bytes'])];
        }catch(\Throwable $error){
            if($this->db->inTransaction())$this->db->rollBack();
            if($runId!==null)Database::query($this->db,"UPDATE raw_storage_runs SET status='failed',finished_at=now(),message=? WHERE id=?",['Manutenção não concluída. Confira os RAW e o arquivo de backup; nenhuma remoção sem verificação foi autorizada.',$runId]);
            throw $error;
        }finally{$this->db->exec('SELECT pg_advisory_unlock(194701,2)');}
    }
}

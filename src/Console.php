<?php
declare(strict_types=1);

namespace ListenLab;

use ListenLab\Http\GuzzleTransport;
use ListenLab\Ingestion\Collector;
use ListenLab\Spotify\Api;
use ListenLab\Spotify\OAuth;
use ListenLab\Storage\RawStore;
use ListenLab\Storage\RawMaintenance;
use ListenLab\Storage\RawBackup;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

final class Console
{
    public static function build(Config $config): Application
    {
        $app = new Application('ListenLab', '0.1.0');
        $app->setCatchExceptions(false);
        $app->setAutoExit(false);
        $db = static fn () => Database::connect($config);
        $raw = new RawStore($config->path('RAW_STORAGE_PATH', 'storage/raw'),$config->path('RAW_ARCHIVE_PATH','storage/archive'));
        $oauth = new OAuth($config, new GuzzleTransport());

        $app->add((new Command('db:migrate'))->setDescription('Aplica migrations SQL com transações, lock e checksum.')
            ->setCode(function (InputInterface $input, OutputInterface $output) use ($config, $db): int {
                $applied = (new Migrator($db(), $config->root . '/migrations'))->migrate();
                $output->writeln($applied ? 'Migrations aplicadas: ' . implode(', ', $applied) : 'Schema atualizado; nenhuma migration pendente.');
                return 0;
            }));
        $app->add((new Command('spotify:auth'))->setDescription('Inicia OAuth Authorization Code; abre somente o scope necessário.')
            ->setCode(function (InputInterface $input, OutputInterface $output) use ($oauth): int {
                $output->writeln('Abra no navegador (válido por 10 minutos):');
                $output->writeln($oauth->begin(), OutputInterface::OUTPUT_RAW);
                $output->writeln('Use o callback local ou execute spotify:auth:complete e cole a URL de retorno no prompt privado.');
                return 0;
            }));
        $app->add((new Command('spotify:auth:complete'))->setDescription('Conclui o OAuth pela URL de retorno, sem colocá-la em argumentos/logs.')
            ->setCode(function (InputInterface $input, OutputInterface $output) use ($oauth): int {
                $question = new Question('URL completa de retorno (entrada oculta): ');
                $question->setHidden(true)->setHiddenFallback(false);
                $url = (new \Symfony\Component\Console\Helper\QuestionHelper())->ask($input, $output, $question);
                if (!is_string($url) || $url === '') {
                    throw new AppError('URL de retorno ausente.');
                }
                $oauth->completeUrl($url);
                $output->writeln('Autorização salva. Execute spotify:sync.');
                return 0;
            }));
        $app->add((new Command('spotify:auth:status'))->setDescription('Mostra datas e scope da autorização, sem tokens.')
            ->setCode(function (InputInterface $input, OutputInterface $output) use ($oauth): int {
                self::json($output, $oauth->status());
                return 0;
            }));

        foreach (['sync','probe'] as $operation) {
            $description = $operation === 'sync' ? 'Coleta recently-played, segue next, preserva RAW e normaliza.' : 'Investiga a janela acessível retrocedendo com cursors.before.';
            $app->add((new Command('spotify:' . $operation))->setDescription($description)
                ->addOption('max-pages', null, InputOption::VALUE_REQUIRED, 'Orçamento de páginas; não indica cobertura completa.')
                ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Itens por página (1–50).')
                ->addOption('json', null, InputOption::VALUE_NONE, 'Saída estruturada.')
                ->setCode(function (InputInterface $input, OutputInterface $output) use ($config, $db, $raw, $oauth, $operation): int {
                    $maxPages = $input->getOption('max-pages') !== null
                        ? self::positive($input->getOption('max-pages'), 10000)
                        : $config->integer($operation === 'sync' ? 'SYNC_MAX_PAGES' : 'PROBE_MAX_PAGES', $operation === 'sync' ? 20 : 100, 1, 10000);
                    $limit = $input->getOption('limit') !== null ? self::positive($input->getOption('limit'), 50) : $config->integer('SPOTIFY_PAGE_LIMIT', 50, 1, 50);
                    $api = new Api(new GuzzleTransport(), $oauth, $config->integer('HTTP_MAX_RETRIES', 2, 0, 5), $config->integer('HTTP_MAX_RETRY_WAIT_SECONDS', 30, 0, 60));
                    $report = (new Collector($db(), $raw, $api))->collect($operation, $maxPages, $limit);
                    return self::report($config, $input, $output, $report);
                }));
        }
        $app->add((new Command('ingest:replay'))->setDescription('Verifica SHA-256 e reprocessa um artefato API registrado, em novo batch.')
            ->addArgument('artifact-id', InputArgument::REQUIRED, 'ID em raw_artifacts.')
            ->addOption('json', null, InputOption::VALUE_NONE)
            ->setCode(function (InputInterface $input, OutputInterface $output) use ($config, $db, $raw): int {
                $report = (new Collector($db(), $raw))->replay(self::positive($input->getArgument('artifact-id'), PHP_INT_MAX));
                return self::report($config, $input, $output, $report);
            }));
        $app->add((new Command('history:list'))->setDescription('Consulta o histórico normalizado em UTC.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Número de eventos.', '20')
            ->addOption('details', null, InputOption::VALUE_NONE, 'Inclui ID, URI, duration, contexto e is_local.')
            ->addOption('json', null, InputOption::VALUE_NONE)
            ->setCode(function (InputInterface $input, OutputInterface $output) use ($db): int {
                $rows = (new History($db()))->recent(self::positive($input->getOption('limit'), 10000));
                if ($input->getOption('json')) {
                    self::json($output, $rows);
                } else {
                    $fields = ['played_at','track','artists','album','source'];
                    if ($input->getOption('details')) {
                        $fields = array_merge($fields, ['track_id','track_uri','duration_ms','is_local','context_type','context_uri']);
                    }
                    (new SymfonyStyle($input, $output))->table($fields, array_map(static fn (array $row) => array_map(static fn (string $key) => self::cell($row[$key]), $fields), $rows));
                }
                return 0;
            }));
        $app->add((new Command('ingestion:list'))->setDescription('Lista batches, incluindo execuções incompletas ou com erros.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Número de batches.', '20')
            ->setCode(function (InputInterface $input, OutputInterface $output) use ($db): int {
                $rows = Database::query($db(), 'SELECT id, operation, status, started_at, records_received, records_inserted, records_skipped, records_failed FROM ingestion_batches ORDER BY id DESC LIMIT ?', [self::positive($input->getOption('limit'), 10000)])->fetchAll(\PDO::FETCH_ASSOC);
                (new SymfonyStyle($input, $output))->table(['batch','operation','status','started_at','received','new','existing','failed'], array_map(static fn ($r) => array_map(self::cell(...), array_values($r)), $rows));
                return 0;
            }));
        $app->add((new Command('raw:verify'))->setDescription('Verifica a integridade de todos os RAW registrados, sem alterá-los.')
            ->setCode(function (InputInterface $input, OutputInterface $output) use ($db, $raw): int {
                $count = 0;
                $stmt = $db()->query('SELECT path, sha256 FROM raw_artifacts ORDER BY id');
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $raw->readVerified($row['path'], $row['sha256']);
                    ++$count;
                }
                $output->writeln("{$count} artefatos verificados; SHA-256 confere.");
                return 0;
            }));
        $app->add((new Command('raw:read'))->setDescription('Lê os bytes originais de um RAW, inclusive compactado ou arquivado.')
            ->addArgument('artifact-id',InputArgument::REQUIRED,'ID registrado em raw_artifacts.')
            ->setCode(function(InputInterface $input,OutputInterface $output)use($db,$raw):int{
                $row=Database::query($db(),'SELECT path,sha256 FROM raw_artifacts WHERE id=?',[self::positive($input->getArgument('artifact-id'),PHP_INT_MAX)])->fetch(\PDO::FETCH_ASSOC);
                if(!$row)throw new AppError('Artefato RAW não encontrado.');
                $output->write($raw->readVerified($row['path'],$row['sha256']),false,OutputInterface::OUTPUT_RAW);return 0;
            }));
        $app->add((new Command('raw:maintain'))->setDescription('Deduplica e compacta RAW; arquiva apenas após restauração de backup verificada.')
            ->addOption('apply',null,InputOption::VALUE_NONE,'Aplica a manutenção; sem esta opção apenas simula.')
            ->addOption('archive',null,InputOption::VALUE_NONE,'Libera conteúdos locais antigos após backup verificado.')
            ->addOption('older-than-days',null,InputOption::VALUE_REQUIRED,'Idade mínima dos conteúdos para arquivar; 0 inclui todos.')
            ->addOption('if-due',null,InputOption::VALUE_NONE,'Executa no máximo uma vez por dia.')
            ->setCode(function(InputInterface $input,OutputInterface $output)use($config,$db,$raw):int{
                $days=filter_var($input->getOption('older-than-days')??$config->get('RAW_ARCHIVE_AFTER_DAYS','30'),FILTER_VALIDATE_INT);
                if($days===false)throw new AppError('Informe uma idade inteira em dias.');
                self::json($output,(new RawMaintenance($db(),$config,$raw))->run((bool)$input->getOption('apply'),(bool)$input->getOption('archive'),$days,(bool)$input->getOption('if-due')));
                return 0;
            }));
        $app->add((new Command('raw:backup:verify'))->setDescription('Restaura um snapshot em banco temporário _test e confere os RAW.')
            ->addArgument('snapshot',InputArgument::REQUIRED,'Nome do snapshot no arquivo privado.')
            ->setCode(function(InputInterface $input,OutputInterface $output)use($config,$db,$raw):int{
                self::json($output,(new RawBackup($db(),$config,$raw))->verify($input->getArgument('snapshot')));return 0;
            }));
        $app->add((new Command('raw:restore'))->setDescription('Recria todos os caminhos RAW de um snapshot em uma pasta nova.')
            ->addArgument('snapshot',InputArgument::REQUIRED,'Nome do snapshot.')
            ->addArgument('destination',InputArgument::REQUIRED,'Nova pasta de restauração, sem arquivos existentes.')
            ->setCode(function(InputInterface $input,OutputInterface $output)use($config,$db,$raw):int{
                $count=(new RawBackup($db(),$config,$raw))->restoreRaw($input->getArgument('snapshot'),$input->getArgument('destination'));
                $output->writeln($count.' referências RAW restauradas e verificadas.');return 0;
            }));
        return $app;
    }

    private static function report(Config $config, InputInterface $input, OutputInterface $output, array $report): int
    {
        $report['report_path'] = (new ReportWriter($config->path('REPORT_STORAGE_PATH', 'storage/reports')))->write($report);
        if ($input->getOption('json')) {
            self::json($output, $report);
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->writeln('Spotify ' . $report['operation']);
            $labels = [
                'batch_id' => 'Batch', 'status' => 'Status', 'api_records_received' => 'API records received',
                'new_source_records' => 'New source records', 'already_ingested' => 'Already ingested',
                'plays_created' => 'Plays created', 'records_failed' => 'Records failed',
                'pages' => 'Pages', 'newest' => 'Newest (UTC)', 'oldest' => 'Oldest (UTC)',
                'covered_seconds' => 'Covered seconds', 'stop_reason' => 'Stop reason', 'report_path' => 'Report',
            ];
            foreach ($labels as $key => $label) {
                $io->writeln($label . ': ' . self::cell($report[$key]));
            }
            foreach ($report['raw_paths'] as $path) {
                $io->writeln('RAW: ' . self::cell($config->path('RAW_STORAGE_PATH', 'storage/raw') . '/' . $path));
            }
            if (isset($report['error'])) {
                $io->error(self::cell($report['error']));
            }
        }
        return match ($report['status']) { 'completed' => 0, 'partial' => 2, default => 1 };
    }

    private static function positive(mixed $value, int $max): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || $number < 1 || $number > $max) {
            throw new AppError("Informe um inteiro entre 1 e {$max}.");
        }
        return $number;
    }

    private static function cell(mixed $value): string
    {
        $text = match (true) { $value === null => 'NULL', $value === true => 'true', $value === false => 'false', default => (string) $value };
        return OutputFormatter::escape(preg_replace('/[\x00-\x1f\x7f]/', ' ', $text));
    }

    private static function json(OutputInterface $output, array $value): void
    {
        $output->writeln(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
    }
}

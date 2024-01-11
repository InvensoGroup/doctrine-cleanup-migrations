<?php

namespace Invenso\DoctrineCleanupMigrations\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'invenso:cleanup:migrations',
    description: 'Makes a diff file from all existing migrations and creates a delete file',
)]
class ClearMigrationsCommand extends Command
{
    protected KernelInterface $kernel;
    protected Filesystem $filesystem;

    public function __construct(KernelInterface $kernel, Filesystem $filesystem)
    {
        $this->kernel = $kernel;
        $this->filesystem = $filesystem;
        parent::__construct();
    }

    protected function configure(): void
    {
        //        $this
        //            ->addArgument('arg1', InputArgument::OPTIONAL, 'Argument description')
        //            ->addOption('option1', null, InputOption::VALUE_NONE, 'Option description')
        //        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $application = $this->getApplication();

        if (null === $application) {
            return -1;
        }

        $output->writeln('Checking for pending migrations first...');

        $args = [
          'command' => 'doctrine:migrations:status',
        ];

        $in = new ArrayInput($args);
        $out = new BufferedOutput();
        $returnCode = $application->doRun($in, $out);

        $data = $this->getArrayFromString($out->fetch());

        if ((int) $data['New Migrations'] >= 1) {
            $output->writeln("You've got one or more pending migrations. Solve this issue first before running the command again.");
            $output->writeln('Run php bin/console doctrine:migrations:status for more information');

            return -1;
        }

        $project_dir = $this->kernel->getProjectDir();

        // read migrations dir.
        // split the contents into an array
        // Save filenames,
        $allFiles = $this->getFilesFromDirectory($project_dir . '/migrations');

        // save the last file separately
        $lastFile = $allFiles[count($allFiles) - 1];

        // Delete all files. This is needed to create a dump file with all the updates thus far.
        foreach ($allFiles as $file) {
            $output->writeln('Deleting file' . $file);
            unlink($project_dir . '/migrations/' . $file);
        }
        try {
            // dump the schema to one migration
            $output->writeln('Attempting to run php bin/console doctrine:migrations:dump-schema...');

            $args = [
                'command' => 'doctrine:migrations:dump-schema',
            ];

            $in = new ArrayInput($args);

            // doRun is used because we don't rollup the migration
            $returnCode = $application->doRun($in, $output);
        } catch (\Throwable $e) {
            throw new \Exception('failed! ' . $e);
        }

        // we scan the directory for the one file that remains
        $allFiles = $this->getFilesFromDirectory($project_dir . '/migrations');

        if (1 != count($allFiles)) {
            return -1;
        }

        /*
         * Renaming the schema dump to the last file present before the deletion of all migrations
         */
        $output->writeln('Renaming ' . $project_dir . '/migrations/' . $allFiles[0] . ' to ' . $project_dir . '/migrations/' . $lastFile);
        rename($project_dir . '/migrations/' . $allFiles[0], $project_dir . '/migrations/' . $lastFile);
        $output->writeln('Renaming classname...');
        $content = file_get_contents($project_dir . '/migrations/' . $lastFile);
        $start = strpos((string) $content, 'class ') + 6;
        $end = strpos((string) $content, ' extends');
        $classname = substr((string) $content, $start, $end - $start);
        $content = str_replace($classname, str_replace('.php', '', $lastFile), (string) $content);

        // because we also changed the classname we need to flush.
        file_put_contents($project_dir . '/migrations/' . $lastFile, $content);

        /**
         * Creating a new migration from scratch which will remove all references out of the DB except for
         * the new one and the newly compressed one.
         */
        $source_path = dirname(__FILE__) . '/../Resources/Templates';

        $date = date('Ymdhis');
        $name = 'Version' . $date;

        $migration_filename = $project_dir . '/migrations/' . $name . '.php';
        $this->filesystem->copy($source_path . '/migration_template', $migration_filename, true);

        $content = file_get_contents($migration_filename);
        if (!$content) {
            return -1;
        }
        $content = str_replace('%className%', $name, $content);
        $migration1 = $date;
        $migration2 = substr($lastFile, 7, 14);
        //        dd($migration2);
        $sqlStatement = '$this->addSql(\'DELETE FROM migration_versions WHERE version NOT LIKE "%' . $migration1 . '" AND version NOT LIKE "%' . $migration2 . '"\')';
        $content = str_replace('%queryStatement%', $sqlStatement, $content);
        file_put_contents($migration_filename, $content);

        $question = "Do you want to exectute the migration? y/n (default is 'n')";
        $answer = $io->ask($question);

        if (('Y' !== $answer) && ('y' !== $answer)) {
            return -1;
        }

        $output->writeln('Attempting migrate...');
        $command = $application->find('doctrine:migration:migrate');

        $args = [
            'command' => 'doctrine:migration:migrate',
            '--no-interaction' => true,
        ];

        $in = new ArrayInput($args);
        $returnCode = $command->run($in, $output);

        $io->success('Command Executed');

        return Command::SUCCESS;
    }

    /**
     * @param string $directory
     *
     * @return array<string>
     */
    private function getFilesFromDirectory(string $directory)
    {
        $rawData = scandir($directory);
        if ('array' != gettype($rawData)) {
            return [];
        }
        $differentiation = array_diff($rawData, ['..', '.']);

        return array_values($differentiation);
    }

    /**
     * @param string $content
     *
     * @return array<string>
     */
    private function getArrayFromString(string $content)
    {
        // trim unwanted characters
        $replaced = preg_replace("/[^A-Za-z0-9:\n ]/", '', $content);
        $rawData = explode("\n", $replaced ?? '');
        // create arrays to separate keys and values;
        $keys = [];
        $values = [];

        foreach ($rawData as $data) {
            if ('' !== $data) {
                $dash = preg_replace('/[:]/', '-', $data, 1);
                $exploded = explode('-', $dash ?? '');

                array_push($keys, trim($exploded[0]));
                isset($exploded[1]) ? array_push($values, trim($exploded[1])) : array_push($values, '');
            }
        }

        return array_combine($keys, $values);
    }
}

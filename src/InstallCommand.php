<?php

namespace Bigfork\CleanInstallInstaller\Console;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;
use GuzzleHttp\Client;
use GuzzleHttp\Event\ProgressEvent;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class InstallCommand extends Command
{
    /**
     * @return void
     */
    protected function configure()
    {
        $this->setName('install')
            ->setDescription('Create a new SilverStripe CleanInstall installation.')
            ->addArgument('directory', InputArgument::REQUIRED);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $directory = $input->getArgument('directory');
        $installPath  = getcwd() . DIRECTORY_SEPARATOR . $directory;

        // If the path already exists, ask for permission to remove it or exit
        if (is_dir($installPath)) {
            $helper = $this->getHelper('question');
            $text = 'The directory "'.$directory.'" already exists, do you want to overwrite it? (Y/N)';
            $question = new ConfirmationQuestion("<question>$text</question> ");

            if (!$helper->ask($input, $output, $question)) {
                return;
            } else {
                $output->writeln("<info>Removing directory \"$directory\"...</info>");
                $this->remove($installPath);
            }
        }

        // Temp filename for zip
        $zipFile = getcwd() . DIRECTORY_SEPARATOR . 'cleaninstall_'.md5(time() . uniqid()) . '.zip';

        $output->writeln("<info>Downloading CleanInstall...</info>");
        $this->download($zipFile, $output);

        $output->writeln(PHP_EOL . "<info>Extracting CleanInstall...</info>");
        $this->extract($zipFile, $installPath)
            ->remove($zipFile);

        $output->writeln(PHP_EOL . "<info>CleanInstall installation complete!</info>");
    }

    /**
     * Download a file. Writes a progress bar to output
     * @param string $zipFile
     * @param OutputInterface $output
     * @return $this
     */
    protected function download($zipFile, OutputInterface $output)
    {
        $client = new Client(array('base_url' => 'https://api.github.com'));
        $request = $client->createRequest('GET', '/repos/feejin/Silverstripe-CleanInstall/zipball/master');

        $progressBar = new ProgressBar($output, 100);
        $progressBar->setFormat('<info>[%bar%]</info> <comment>%percent%%</comment>');

        // Progress event - update the progress bar
        $request->getEmitter()->on('progress', function (ProgressEvent $event) use ($progressBar) {
            if ($event->downloaded && $event->downloadSize) {
                $percent = round($event->downloaded / $event->downloadSize * 100);
                $progressBar->setProgress($percent);
            }
        });

        $response = $client->send($request);
        $progressBar->finish();

        file_put_contents($zipFile, $response->getBody());

        return $this;
    }

    /**
     * Extract a zip file
     * @param string $file
     * @param string $target
     * @return $this
     */
    protected function extract($file, $target)
    {
        $archive = new ZipArchive;
        $archive->open($file);

        // Githubs archives all contain a "<user>-<repo>-<commithash>" directory
        // that contains all the files, so we need to jump into that. ZipArchive
        // doesn't let us do it, so we re-write paths
        $commitDirectory = $archive->getNameIndex(0);
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $filename = $archive->getNameIndex($i);
            // Strip off the "<user>-<repo>-<commithash>" from the archive file name
            $destPath = $target . '/' . substr($archive->getNameIndex($i), strlen($commitDirectory));

            // Check that the directory exists in our target
            if (!is_dir(dirname($destPath))) {
                $this->makeDir(dirname($destPath));
            }
            
            // We can't copy an empty directory like this, so skip them as they'll
            // be created above anyway
            if (substr($filename, -1) !== '/') {
                copy("zip://" . $file . "#" . $filename, $destPath);
            }
        }
        $archive->close();

        return $this;
    }

    /**
     * Creates a directory, recursively iterating through, and creating, missing
     * parent directories
     * @param string $path
     */
    protected function makeDir($path)
    {
        if (!file_exists(dirname($path))) {
            $this->makeDir(dirname($path));
        }
        if (!file_exists($path)) {
            mkdir($path);
        }
    }

    /**
     * Remove the given file or directory. If a directory is passed, we recursively
     * remove all contained files and directories before removing the directory
     * itself
     * @param string $fileOrDirectory
     * @return $this
     */
    protected function remove($fileOrDirectory)
    {
        if (is_dir($fileOrDirectory)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fileOrDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileinfo) {
                $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                $todo($fileinfo->getRealPath());
            }

            rmdir($fileOrDirectory);
        } else {
            unlink($fileOrDirectory);
        }

        return $this;
    }
}

<?php
declare(strict_types = 1);

namespace BrowscapReader\Command;

use BrowscapPHP\Browscap;
use BrowscapPHP\Exception\InvalidArgumentException;
use BrowscapPHP\Exception\UnknownBrowserException;
use BrowscapPHP\Exception\UnknownBrowserTypeException;
use BrowscapPHP\Exception\UnknownDeviceException;
use BrowscapPHP\Exception\UnknownEngineException;
use BrowscapPHP\Exception\UnknownPlatformException;
use BrowscapReader\Helper\Filesystem;
use BrowscapReader\Helper\LoggerHelper;
use BrowscapReader\Util\Logfile\ReaderCollection;
use BrowscapReader\Util\Logfile\ReaderException;
use BrowscapReader\Util\Logfile\ReaderFactory;
use Doctrine\Common\Cache\FilesystemCache;
use Roave\DoctrineSimpleCache\SimpleCacheAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Commands to parse a log file and parse the useragents in it
 */
class LogfileCommand extends Command
{
    /**
     * @var array
     */
    private $undefinedClients = [];

    /**
     * @var array
     */
    private $uas = [];

    /**
     * @var array
     */
    private $uasWithType = [];

    /**
     * @var int
     */
    private $countOk = 0;

    /**
     * @var int
     */
    private $countNok = 0;

    /**
     * @var int
     */
    private $totalCount = 0;

    /**
     * @var string
     */
    private $defaultCacheFolder;

    public function __construct(string $defaultCacheFolder)
    {
        $this->defaultCacheFolder = $defaultCacheFolder;

        parent::__construct();
    }

    protected function configure() : void
    {
        $this
            ->setName('browscap:log')
            ->setDescription('Parses the supplied webserver log file.')
            ->addArgument(
                'output',
                InputArgument::REQUIRED,
                'Path to output log file',
                null
            )
            ->addOption(
                'log-file',
                'f',
                InputOption::VALUE_REQUIRED,
                'Path to a webserver log file'
            )
            ->addOption(
                'log-dir',
                'd',
                InputOption::VALUE_REQUIRED,
                'Path to webserver log directory'
            )
            ->addOption(
                'include',
                'i',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Include glob expressions for log files in the log directory',
                ['*.log', '*.log*.gz', '*.log*.bz2']
            )
            ->addOption(
                'exclude',
                'e',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Exclude glob expressions for log files in the log directory',
                ['*error*']
            )
            ->addOption(
                'cache',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Where the cache files are located',
                $this->defaultCacheFolder
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output) : void
    {
        if (! $input->getOption('log-file') && ! $input->getOption('log-dir')) {
            throw InvalidArgumentException::oneOfCommandArguments('log-file', 'log-dir');
        }

        $logger = LoggerHelper::createDefaultLogger($output);

        $fileCache = new FilesystemCache($input->getOption('cache'));
        $cache = new SimpleCacheAdapter($fileCache);

        $browscap = new Browscap($cache, $logger);
        $collection = ReaderFactory::factory();
        $fs = new Filesystem();

        /** @var \Symfony\Component\Finder\SplFileInfo $file */
        foreach ($this->getFiles($input) as $file) {
            $this->uas = [];
            $path = $this->getPath($file);

            $this->countOk = 0;
            $this->countNok = 0;

            $logger->info('Analyzing file "' . $file->getPathname() . '"');

            $lines = file($path);

            if (empty($lines)) {
                $logger->info('Skipping empty file "' . $file->getPathname() . '"');

                continue;
            }

            $this->totalCount = count($lines);

            foreach ($lines as $line) {
                $this->handleLine(
                    $output,
                    $collection,
                    $browscap,
                    $line
                );
            }

            $this->outputProgress($output, '', true);

            arsort($this->uas, SORT_NUMERIC);

            try {
                $fs->dumpFile(
                    $input->getArgument('output') . '/output.txt',
                    implode(PHP_EOL, array_unique($this->undefinedClients))
                );
            } catch (IOException $e) {
                // do nothing
            }

            try {
                $fs->dumpFile(
                    $input->getArgument('output') . '/output-with-amount.txt',
                    $this->createAmountContent()
                );
            } catch (IOException $e) {
                // do nothing
            }

            try {
                $fs->dumpFile(
                    $input->getArgument('output') . '/output-with-amount-and-type.txt',
                    $this->createAmountTypeContent()
                );
            } catch (IOException $e) {
                // do nothing
            }
        }

        $outputFile = $input->getArgument('output') . '/output.txt';

        try {
            $fs->dumpFile(
                $outputFile,
                implode(PHP_EOL, array_unique($this->undefinedClients))
            );
        } catch (IOException $e) {
            throw new \UnexpectedValueException('writing to file "' . $outputFile . '" failed', 0, $e);
        }

        try {
            $fs->dumpFile(
                $input->getArgument('output') . '/output-with-amount.txt',
                $this->createAmountContent()
            );
        } catch (IOException $e) {
            // do nothing
        }

        try {
            $fs->dumpFile(
                $input->getArgument('output') . '/output-with-amount-and-type.txt',
                $this->createAmountTypeContent()
            );
        } catch (IOException $e) {
            // do nothing
        }
    }

    private function createAmountContent() : string
    {
        $counts = [];

        foreach ($this->uasWithType as $uas) {
            foreach ($uas as $userAgentString => $count) {
                if (isset($counts[$userAgentString])) {
                    $counts[$userAgentString] += $count;
                } else {
                    $counts[$userAgentString] = $count;
                }
            }
        }

        $content = '';

        arsort($counts, SORT_NUMERIC);

        foreach ($counts as $agentOfLine => $count) {
            $content .= "$count\t$agentOfLine\n";
        }

        return $content;
    }

    private function createAmountTypeContent() : string
    {
        $content = '';
        $types = ['B', 'T', 'P', 'D', 'N', 'U'];

        foreach ($types as $type) {
            if (! isset($this->uasWithType[$type])) {
                continue;
            }

            arsort($this->uasWithType[$type], SORT_NUMERIC);

            foreach ($this->uasWithType[$type] as $agentOfLine => $count) {
                $content .= "$type\t$count\t$agentOfLine\n";
            }
        }

        return $content;
    }

    private function handleLine(
        OutputInterface $output,
        ReaderCollection $collection,
        Browscap $browscap,
        string $line
    ) : void {
        $userAgentString = '';

        try {
            $userAgentString = $collection->read($line);

            try {
                $this->getResult($browscap->getBrowser($userAgentString));
            } catch (\Exception $e) {
                $this->undefinedClients[] = $userAgentString;

                throw $e;
            }

            $type = '.';
            ++$this->countOk;
        } catch (ReaderException $e) {
            $type = 'E';
            ++$this->countNok;
        } catch (UnknownBrowserTypeException $e) {
            $type = 'T';
            ++$this->countNok;
        } catch (UnknownBrowserException $e) {
            $type = 'B';
            ++$this->countNok;
        } catch (UnknownPlatformException $e) {
            $type = 'P';
            ++$this->countNok;
        } catch (UnknownDeviceException $e) {
            $type = 'D';
            ++$this->countNok;
        } catch (UnknownEngineException $e) {
            $type = 'N';
            ++$this->countNok;
        } catch (\Exception $e) {
            $type = 'U';
            ++$this->countNok;
        }

        $this->outputProgress($output, $type);

        // count all useragents
        if (isset($this->uas[$userAgentString])) {
            ++$this->uas[$userAgentString];
        } else {
            $this->uas[$userAgentString] = 1;
        }

        if ('.' !== $type && 'E' !== $type) {
            // count all undetected useragents grouped by detection error
            if (! isset($this->uasWithType[$type])) {
                $this->uasWithType[$type] = [];
            }

            if (isset($this->uasWithType[$type][$userAgentString])) {
                ++$this->uasWithType[$type][$userAgentString];
            } else {
                $this->uasWithType[$type][$userAgentString] = 1;
            }
        }
    }

    private function outputProgress(OutputInterface $output, string $result, bool $end = false) : void
    {
        if (0 === ($this->totalCount % 70) || $end) {
            $formatString = '  %' . strlen($this->countOk) . 'd OK, %' . strlen($this->countNok) . 'd NOK, Summary %'
                . strlen($this->totalCount) . 'd';

            if ($end) {
                $result = str_pad($result, 70 - ($this->totalCount % 70), ' ', STR_PAD_RIGHT);
            }

            $endString = sprintf($formatString, $this->countOk, $this->countNok, $this->totalCount);

            $output->writeln($result . $endString);

            return;
        }

        $output->write($result);
    }

    private function getResult(\stdClass $result) : string
    {
        if ('Default Browser' === $result->browser) {
            throw new UnknownBrowserException('Unknown browser found');
        }

        if ('unknown' === $result->browser_type) {
            throw new UnknownBrowserTypeException('Unknown browser type found');
        }

        if (in_array($result->browser_type, ['Bot/Crawler', 'Library'])) {
            return '.';
        }

        if ('unknown' === $result->platform) {
            throw new UnknownPlatformException('Unknown platform found');
        }

        if ('unknown' === $result->device_type) {
            throw new UnknownDeviceException('Unknown device type found');
        }

        if ('unknown' === $result->renderingengine_name) {
            throw new UnknownEngineException('Unknown rendering engine found');
        }

        return '.';
    }

    private function getFiles(InputInterface $input) : Finder
    {
        $finder = Finder::create();

        if ($input->getOption('log-file')) {
            $file = $input->getOption('log-file');
            $finder->append(Finder::create()->in(dirname($file))->name(basename($file)));
        }

        if ($input->getOption('log-dir')) {
            $dirFinder = Finder::create()
                ->in($input->getOption('log-dir'));
            array_map([$dirFinder, 'name'], $input->getOption('include'));
            array_map([$dirFinder, 'notName'], $input->getOption('exclude'));

            $finder->append($dirFinder);
        }

        return $finder;
    }

    private function getPath(SplFileInfo $file) : string
    {
        switch ($file->getExtension()) {
            case 'gz':
                $path = 'compress.zlib://' . $file->getPathname();

                break;
            case 'bz2':
                $path = 'compress.bzip2://' . $file->getPathname();

                break;
            default:
                $path = $file->getPathname();

                break;
        }

        return $path;
    }
}

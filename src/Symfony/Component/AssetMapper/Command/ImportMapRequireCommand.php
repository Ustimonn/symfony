<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\AssetMapper\Command;

use Symfony\Component\AssetMapper\ImportMap\ImportMapEntry;
use Symfony\Component\AssetMapper\ImportMap\ImportMapManager;
use Symfony\Component\AssetMapper\ImportMap\PackageRequireOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @author Kévin Dunglas <kevin@dunglas.dev>
 */
#[AsCommand(name: 'importmap:require', description: 'Requires JavaScript packages')]
final class ImportMapRequireCommand extends Command
{
    public function __construct(
        private readonly ImportMapManager $importMapManager,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('packages', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'The packages to add')
            ->addOption('entrypoint', null, InputOption::VALUE_NONE, 'Make the package(s) an entrypoint?')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'The local path where the package lives relative to the project root')
            ->setHelp(<<<'EOT'
The <info>%command.name%</info> command adds packages to <comment>importmap.php</comment> usually
by finding a CDN URL for the given package and version.

For example:

    <info>php %command.full_name% lodash</info>
    <info>php %command.full_name% "lodash@^4.15"</info>

You can also require specific paths of a package:

    <info>php %command.full_name% "chart.js/auto"</info>

Or download one package/file, but alias its name in your import map:

    <info>php %command.full_name% "vue/dist/vue.esm-bundler.js=vue"</info>

The <info>download</info> option will download the package locally and point the
importmap to it. Use this if you want to avoid using a CDN or if you want to
ensure that the package is available even if the CDN is down.

Sometimes, a package may require other packages and multiple new items may be added
to the import map.

You can also require multiple packages at once:

    <info>php %command.full_name% "lodash@^4.15" "@hotwired/stimulus"</info>

EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $packageList = $input->getArgument('packages');
        $path = null;
        if ($input->getOption('path')) {
            if (\count($packageList) > 1) {
                $io->error('The "--path" option can only be used when you require a single package.');

                return Command::FAILURE;
            }

            $path = $input->getOption('path');
            if (!is_file($path)) {
                $path = $this->projectDir.'/'.$path;

                if (!is_file($path)) {
                    $io->error(sprintf('The path "%s" does not exist.', $input->getOption('path')));

                    return Command::FAILURE;
                }
            }
        }

        $packages = [];
        foreach ($packageList as $packageName) {
            $parts = ImportMapManager::parsePackageName($packageName);
            if (null === $parts) {
                $io->error(sprintf('Package "%s" is not a valid package name format. Use the format PACKAGE@VERSION - e.g. "lodash" or "lodash@^4"', $packageName));

                return Command::FAILURE;
            }

            $packages[] = new PackageRequireOptions(
                $parts['package'],
                $parts['version'] ?? null,
                $parts['alias'] ?? $parts['package'],
                $path,
                $input->getOption('entrypoint'),
            );
        }

        $newPackages = $this->importMapManager->require($packages);
        if (1 === \count($newPackages)) {
            $newPackage = $newPackages[0];
            $message = sprintf('Package "%s" added to importmap.php', $newPackage->importName);

            $message .= '.';
        } else {
            $names = array_map(fn (ImportMapEntry $package) => $package->importName, $newPackages);
            $message = sprintf('%d new items (%s) added to the importmap.php!', \count($newPackages), implode(', ', $names));
        }

        $messages = [$message];

        if (1 === \count($newPackages)) {
            $messages[] = sprintf('Use the new package normally by importing "%s".', $newPackages[0]->importName);
        }

        $io->success($messages);

        return Command::SUCCESS;
    }
}

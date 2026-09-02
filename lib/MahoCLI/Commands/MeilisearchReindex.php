<?php

/**
 * Meilisearch Search — Maho CLI reindex command
 *
 * @category  Meilisearch
 * @package   Meilisearch_Search
 * @license   https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MahoCLI\Commands;

use Mage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'meilisearch:reindex',
    description: 'Reindex Meilisearch indexes (products, categories, pages, faqs, blog, suggestions, amasty_pages, additional_sections, or all)',
)]
class MeilisearchReindex extends BaseMahoCommand
{
    private const TYPES = [
        'products', 'categories', 'pages', 'faqs', 'blog',
        'suggestions', 'amasty_pages', 'additional_sections', 'all',
    ];

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument(
                'type',
                InputArgument::OPTIONAL,
                'Entity type: ' . implode('|', self::TYPES),
                'all',
            )
            ->addOption(
                'store',
                's',
                InputOption::VALUE_OPTIONAL,
                'Store ID to reindex (applies to products only)',
            );
    }

    /**
     * Delete any leftover *_products_tmp index before rebuilding.
     *
     * A rebuild fills a _tmp index page by page and swaps it in at the end. If the
     * run dies partway - most often on a database out-of-memory error - the
     * half-filled _tmp is left behind. The next run then fails to create it
     * ("Index `..._tmp` already exists"), carries on appending into the stale
     * index, and swaps that incomplete result into production. Every run reports
     * success while the live index quietly stays short, which is how a store can
     * lose a couple of hundred products across many "full" reindexes.
     *
     * Deleting first makes a failed run harmless: the live index is untouched and
     * the next attempt starts clean. Deletion is safe because _tmp is scratch space
     * that only exists between the start of a rebuild and its swap.
     */
    private function clearProductTmpIndexes(mixed $storeId, OutputInterface $output): void
    {
        /** @var \Meilisearch_Search_Helper_Entity_Producthelper $productHelper */
        $productHelper = Mage::helper('meilisearch_search/entity_producthelper');
        /** @var \Meilisearch_Search_Helper_Meilisearchhelper $meilisearchHelper */
        $meilisearchHelper = Mage::helper('meilisearch_search/meilisearchhelper');

        $storeIds = $storeId !== null && $storeId !== ''
            ? [(int) $storeId]
            : array_keys(Mage::app()->getStores());

        foreach ($storeIds as $id) {
            try {
                $tmpName = $productHelper->getIndexName($id, true);
                $meilisearchHelper->deleteIndex($tmpName);
                $output->writeln('<comment>Cleared stale index ' . $tmpName . '</comment>');
            } catch (\Throwable $e) {
                // A missing _tmp is the normal case - nothing to clear.
                $output->writeln('<comment>No stale tmp index for store ' . $id . '</comment>');
            }
        }
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initMaho();

        $type = (string) $input->getArgument('type');
        $storeId = $input->getOption('store');

        if (!in_array($type, self::TYPES, true)) {
            $output->writeln('<error>Unknown type "' . $type . '". Valid: ' . implode(', ', self::TYPES) . '</error>');
            return Command::FAILURE;
        }

        /** @var \Meilisearch_Search_Model_Resource_Engine $engine */
        $engine = Mage::getResourceSingleton('meilisearch_search/engine');

        $run = function (string $label, callable $fn) use ($output): void {
            $output->writeln('<info>Reindexing ' . $label . '...</info>');
            $fn();
            $output->writeln('<info>' . $label . ' reindexed.</info>');
        };

        try {
            switch ($type) {
                case 'products':
                    $this->clearProductTmpIndexes($storeId, $output);
                    $run('products', fn() => $engine->rebuildProducts($storeId));
                    break;
                case 'categories':
                    $run('categories', fn() => $engine->rebuildCategories());
                    break;
                case 'pages':
                    $run('pages', fn() => $engine->rebuildPages());
                    break;
                case 'faqs':
                    $run('FAQs', fn() => $engine->rebuildFaqs());
                    break;
                case 'blog':
                    $run('blog posts', fn() => $engine->rebuildBlog());
                    break;
                case 'suggestions':
                    $run('suggestions', fn() => $engine->rebuildSuggestions());
                    break;
                case 'amasty_pages':
                    $run('Amasty pages', fn() => $engine->rebuildAmastyPages());
                    break;
                case 'additional_sections':
                    $run('additional sections', fn() => $engine->rebuildAdditionalSections());
                    break;
                case 'all':
                    $this->clearProductTmpIndexes($storeId, $output);
                    $run('products', fn() => $engine->rebuildProducts($storeId));
                    if ($storeId) {
                        $output->writeln('<comment>Store filter only applies to products; skipping store-agnostic entities.</comment>');
                        break;
                    }
                    $run('categories', fn() => $engine->rebuildCategories());
                    $run('pages', fn() => $engine->rebuildPages());
                    $run('FAQs', fn() => $engine->rebuildFaqs());
                    $run('blog posts', fn() => $engine->rebuildBlog());
                    $run('Amasty pages', fn() => $engine->rebuildAmastyPages());
                    $run('additional sections', fn() => $engine->rebuildAdditionalSections());
                    $run('suggestions', fn() => $engine->rebuildSuggestions());
                    break;
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>Reindex failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Done.</info>');
        return Command::SUCCESS;
    }
}

<?php

/*
 * This file is part of the Yosymfony\Spress.
 *
 * (c) YoSymfony <http://github.com/yosymfony>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Yosymfony\Spress\Core\ContentManager;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Yosymfony\Spress\Core\ContentManager\Collection\CollectionManager;
use Yosymfony\Spress\Core\ContentManager\Collection\CollectionInterface;
use Yosymfony\Spress\Core\ContentManager\Converter\ConverterManager;
use Yosymfony\Spress\Core\ContentManager\Generator\GeneratorManager;
use Yosymfony\Spress\Core\ContentManager\Permalink\PermalinkGeneratorInterface;
use Yosymfony\Spress\Core\ContentManager\Renderizer\RenderizerInterface;
use Yosymfony\Spress\Core\ContentManager\SiteAttribute\SiteAttributeInterface;
use Yosymfony\Spress\Core\DataSource\DataSourceManager;
use Yosymfony\Spress\Core\DataWriter\DataWriterInterface;
use Yosymfony\Spress\Core\DataSource\ItemInterface;
use Yosymfony\Spress\Core\DependencyResolver\DependencyResolver;
use Yosymfony\Spress\Core\Exception\AttributeValueException;
use Yosymfony\Spress\Core\Plugin\Event;
use Yosymfony\Spress\Core\IO\IOInterface;
use Yosymfony\Spress\Core\IO\NullIO;
use Yosymfony\Spress\Core\Plugin\PluginManager;
use Yosymfony\Spress\Core\Support\AttributesResolver;
use Yosymfony\Spress\Core\Support\ItemCollection;
use Yosymfony\Spress\Core\Support\StringWrapper;

/**
 * Content manager.
 *
 * Attributes with special meaning:
 *  - layout: (string) layout used for rendering an item.
 *  - avoid_renderizer: (bool) Avoids the renderizer phase.
 *  - generator: (string) The name of the generator.
 *  - draft: (bool) Indicates if the item's content is a draft.
 *  - output: (bool) Avoid the persistence of an item.
 *
 * @author Victor Puertas <vpgugr@gmail.com>
 */
class ContentManager
{
    private $dataSourceManager;
    private $dataWriter;
    private $generatorManager;
    private $converterManager;
    private $CollectionManager;
    private $permalinkGenerator;
    private $siteAttribute;
    private $renderizer;
    private $pluginManager;
    private $dependencyResolver;
    private $eventDispatcher;
    private $io;
    private $timezone;
    private $safe;
    private $processDraft;
    private $attributes;
    private $spressAttributes;
    private $itemCollection;

    /**
     * Constructor.
     *
     * @param DataSourceManager           $dataSourceManager
     * @param DataWriterInterface         $dataWriter
     * @param ConverterManager            $converterManager
     * @param CollectionManager           $CollectionManager
     * @param PermalinkGeneratorInterface $permalinkGenerator
     * @param RenderizerInterface         $renderizer
     * @param SiteAttributeInterface      $siteAttribute
     * @param PluginManager               $pluginManager
     * @param EventDispatcher             $eventDispatcher
     */
    public function __construct(
        DataSourceManager $dataSourceManager,
        DataWriterInterface $dataWriter,
        GeneratorManager $generatorManager,
        ConverterManager $converterManager,
        CollectionManager $CollectionManager,
        PermalinkGeneratorInterface $permalinkGenerator,
        RenderizerInterface $renderizer,
        SiteAttributeInterface $siteAttribute,
        PluginManager $pluginManager,
        EventDispatcher $eventDispatcher
    ) {
        $this->dataSourceManager = $dataSourceManager;
        $this->dataWriter = $dataWriter;
        $this->generatorManager = $generatorManager;
        $this->converterManager = $converterManager;
        $this->CollectionManager = $CollectionManager;
        $this->permalinkGenerator = $permalinkGenerator;
        $this->renderizer = $renderizer;
        $this->siteAttribute = $siteAttribute;
        $this->pluginManager = $pluginManager;
        $this->eventDispatcher = $eventDispatcher;

        $this->attributes = [];
        $this->spressAttributes = [];
        $this->itemCollection = new ItemCollection();

        $this->setIO(new NullIO());
    }

    /**
     * Parses a site.
     *
     * @param array  $attributes       The site attributes
     * @param array  $spressAttributes
     * @param bool   $draft            Include draft posts
     * @param bool   $safe             True for disabling custom plugins
     * @param string $timezone         Sets the time zone. @see http://php.net/manual/en/timezones.php More time zones
     *
     * @return ItemInterface[] Items of the site
     */
    public function parseSite(array $attributes, array $spressAttributes, $draft = false, $safe = false, $timezone = 'UTC')
    {
        $this->attributes = $attributes;
        $this->spressAttributes = $spressAttributes;
        $this->safe = $safe;
        $this->timezone = $timezone;
        $this->processDraft = $draft;

        $this->reset();
        $this->setUp();
        $this->initializePlugins();
        $this->process();
        $this->finish();

        return $this->itemCollection->all();
    }

    /**
     * Sets the dependency resolver.
     *
     * @param DependencyResolver $dependencyResolver
     *
     * @throws LogicException If there is a previous instance the dependency resolver.
     */
    public function setDependencyResolver(DependencyResolver $dependencyResolver)
    {
        if ($this->dependencyResolver != null) {
            throw new \LogicException('There is an instance of DependencyManager class in class ContentManager.');
        }

        $this->dependencyResolver = $dependencyResolver;
    }

    /**
     * Sets the IO.
     *
     * @param IOInterface $io
     */
    public function setIO(IOInterface $io)
    {
        $this->io = $io;
    }

    private function reset()
    {
        $this->itemCollection->clear();
    }

    private function setUp()
    {
        $this->configureTimezone($this->timezone);
    }

    private function initializePlugins()
    {
        if ($this->safe === false) {
            $this->io->write('Initializing plugins', true, IOInterface::VERBOSITY_VERBOSE);
            $this->pluginManager->callInitialize();
        }
    }

    private function process()
    {
        $itemsGenerator = [];

        $this->writeVMessage('Starting to process items');

        $event = $this->eventDispatcher->dispatch('spress.start', new Event\EnvironmentEvent(
            $this->dataSourceManager,
            $this->dataWriter,
            $this->converterManager,
            $this->generatorManager,
            $this->renderizer,
            $this->io,
            $this->attributes
        ));

        $this->dataWriter = $event->getDataWriter();
        $this->dataWriter->setUp();

        $this->renderizer = $event->getRenderizer();
        $this->renderizer->clear();

        $this->prepareSiteAttributes();

        $this->dataSourceManager->load();
        $this->writeVMessage('Data sources loaded');

        $items = $this->dataSourceManager->getItems();
        $layoutItems = $this->dataSourceManager->getLayouts();

        foreach ($items as $item) {
            if ($this->isGenerator($item)) {
                $itemsGenerator[$item->getId()] = $item;

                continue;
            }

            $this->processCollection($item);
            $this->processDraftIfPost($item);
            $this->processOutputAttribute($item);

            $this->itemCollection->add($item);
        }

        $this->writeVMessage('Collections, drafts and output attributes processed');

        foreach ($itemsGenerator as $item) {
            $this->processGenerator($item);
        }

        $this->writeVMessage('Generators processed');

        $this->sortItems();

        $this->prepareRenderizer();

        foreach ($this->itemCollection as $item) {
            $this->convertItem($item);
            $this->processPermalink($item);
            $this->siteAttribute->setItem($item);
        }

        $this->writeVMessage(
            [
                'Converters has been applied to the items',
                'Permalinks ready',
            ]
        );

        foreach ($this->itemCollection as $item) {
            $this->renderBlocks($item);
        }

        $this->writeVMessage('Render over blocks has been applied');

        foreach ($this->itemCollection as $item) {
            $this->renderPage($item, $layoutItems);
            $this->dataWriter->write($item);
        }

        $this->writeVMessage('Render over pages has been applied');
    }

    private function finish()
    {
        $this->dataWriter->tearDown();

        $event = new Event\FinishEvent($this->itemCollection->all(), $this->siteAttribute->getAttributes());

        $this->eventDispatcher->dispatch('spress.finish', $event);

        $this->pluginManager->tearDown();
    }

    private function prepareSiteAttributes()
    {
        $this->siteAttribute->initialize($this->attributes);

        $this->siteAttribute->setAttribute('spress', $this->spressAttributes);
        $this->siteAttribute->setAttribute('site.drafts', $this->processDraft);
        $this->siteAttribute->setAttribute('site.safe', $this->safe);
        $this->siteAttribute->setAttribute('site.timezone', $this->timezone);
    }

    private function processCollection(ItemInterface $item)
    {
        $collection = $this->CollectionManager->getCollectionForItem($item);
        $collectionName = $collection->getName();
        $collectionNamePath = sprintf('site.collections.%s', $this->escapeDot($collectionName));

        $attributes = $item->getAttributes();
        $attributes['collection'] = $collectionName;

        $newAttributes = array_merge($collection->getAttributes(), $attributes);

        $item->setAttributes($newAttributes);
        $item->setCollection($collectionName);

        $this->siteAttribute->setAttribute($collectionNamePath, $this->getCollectionAttributes($collection));
        $this->writeVvMessage(sprintf(
            'The collection for the item with id: "%s" is "%s"',
            $item->getId(),
            $collectionName
        ));
    }

    private function processDraftIfPost(ItemInterface $item)
    {
        $attributes = array_replace(['draft' => false], $item->getAttributes());

        if (is_bool($attributes['draft']) === false) {
            throw new AttributeValueException('Invalid value. Expected boolean.', 'draft', $item->getPath(ItemInterface::SNAPSHOT_PATH_RELATIVE));
        }

        if ($item->getCollection() === 'posts' && $attributes['draft'] === true) {
            if ($this->processDraft === false) {
                $attributes['output'] = false;

                $item->setAttributes($attributes);
            }
        }
    }

    private function processOutputAttribute(ItemInterface $item)
    {
        $attributes = $item->getAttributes();

        if (array_key_exists('output', $attributes)) {
            $output = $attributes['output'];

            if (is_bool($output) === false) {
                throw new AttributeValueException('Invalid value. Expected boolean.', 'output', $item->getPath(ItemInterface::SNAPSHOT_PATH_RELATIVE));
            }

            if ($output === false) {
                $item->setPath('', ItemInterface::SNAPSHOT_PATH_RELATIVE);
                $item->setPath('', ItemInterface::SNAPSHOT_PATH_SOURCE);
            }
        }
    }

    private function processGenerator(ItemInterface $item)
    {
        $this->writeVvMessage(sprintf('Processing the generator with id: "%s"', $item->getId()));

        $attributes = $item->getAttributes();

        $generator = $this->generatorManager->getGenerator($attributes['generator']);
        $items = $generator->generateItems($item, $this->itemCollection->all([], true));

        foreach ($items as $item) {
            if ($this->itemCollection->has($item->getId()) === true) {
                throw new \RuntimeException(
                    sprintf(
                        'A previous item exists with the same id: "%s". Generator: "%s".',
                        $item->getId(),
                        $attributes['generator']
                    )
                );
            }

            $this->processCollection($item);
            $this->itemCollection->add($item);
        }
    }

    private function sortItems()
    {
        $resolver = new AttributesResolver();
        $resolver->setDefault('sort_by', '', 'string')
            ->setDefault('sort_type', 'descending', 'string')
            ->setValidator('sort_type', function ($value) {
                switch ($value) {
                    case 'descending':
                    case 'ascending':
                        return true;
                    default:
                        return false;
                }
            });

        foreach ($this->CollectionManager->getCollectionItemCollection() as $collection) {
            $attributes = $resolver->resolve($collection->getAttributes());

            if (empty($attributes['sort_by']) === true) {
                continue;
            }

            $sortBy = $attributes['sort_by'];
            $isDescending = $attributes['sort_type'] === 'descending';
            $this->itemCollection->sortItems($sortBy, $isDescending, [$collection->getName()]);
            $this->setRelationships($collection->getName());
        }

        $this->writeVMessage('Items sorted');
    }

    private function setRelationships($collection)
    {
        $priorItem = null;
        $items = $this->itemCollection->all([$collection]);

        if (count($items) == 0) {
            return;
        }

        while (($item = current($items)) !== false) {
            if (is_null($priorItem) === false) {
                $item->getRelationshipCollection()->add('prior', $priorItem);
            }

            if (($nextItem = next($items)) !== false) {
                $item->getRelationshipCollection()->add('next', $nextItem);
            }

            $priorItem = $item;
        }
    }

    private function isGenerator(ItemInterface $item)
    {
        $attributes = $item->getAttributes();

        return isset($attributes['generator']);
    }

    private function prepareRenderizer()
    {
        $layouts = $this->dataSourceManager->getLayouts();
        $includes = $this->dataSourceManager->getIncludes();

        foreach ($layouts as $item) {
            $this->renderizer->addLayout($item->getId(), $item->getContent(), $item->getAttributes());
        }

        foreach ($includes as $item) {
            $this->renderizer->addInclude($item->getId(), $item->getContent(), $item->getAttributes());
        }
    }

    private function convertItem(ItemInterface $item)
    {
        $this->writeVvMessage(sprintf('Applying a converter to the item with id: "%s"', $item->getId()));
        $path = $item->getPath(ItemInterface::SNAPSHOT_PATH_RELATIVE);

        if ($item->isBinary() === true) {
            $item->setPath($path, ItemInterface::SNAPSHOT_PATH_RELATIVE_AFTER_CONVERT);

            return;
        }

        $this->eventDispatcher->dispatch('spress.before_convert', new Event\ContentEvent(
            $item,
            ItemInterface::SNAPSHOT_RAW,
            ItemInterface::SNAPSHOT_PATH_RELATIVE
        ));

        $result = $this->converterManager->convertItem($item);

        $str = new StringWrapper($path);
        $filenameWithoutExtension = $str->deleteSufix($result->getInputExtension());
        $newPath = $filenameWithoutExtension.$result->getExtension();

        $item->setContent($result->getResult(), ItemInterface::SNAPSHOT_AFTER_CONVERT);
        $item->setPath($newPath, ItemInterface::SNAPSHOT_PATH_RELATIVE_AFTER_CONVERT);

        $this->eventDispatcher->dispatch('spress.after_convert', new Event\ContentEvent(
            $item,
            ItemInterface::SNAPSHOT_AFTER_CONVERT,
            ItemInterface::SNAPSHOT_PATH_RELATIVE_AFTER_CONVERT
        ));
    }

    private function processPermalink(ItemInterface $item)
    {
        $permalink = $this->permalinkGenerator->getPermalink($item);

        $item->setPath($permalink->getPath(), ItemInterface::SNAPSHOT_PATH_PERMALINK);

        $attributes = $item->getAttributes();
        $attributes['url'] = $permalink->getUrlPath();

        $item->setAttributes($attributes);

        $this->writeVvMessage(sprintf(
            'Permalink for the item with id: "%s" is "%s"',
            $item->getId(),
            $attributes['url']
        ));
    }

    private function renderBlocks(ItemInterface $item)
    {
        if ($this->isRenderizerExcluded($item) === true) {
            return;
        }

        $this->writeVvMessage(sprintf(
            'Rendering blocks for the item with id: "%s"',
            $item->getId()
        ));

        $this->eventDispatcher->dispatch('spress.before_render_blocks', new Event\RenderEvent(
            $item,
            ItemInterface::SNAPSHOT_AFTER_CONVERT,
            ItemInterface::SNAPSHOT_PATH_RELATIVE_AFTER_CONVERT
        ));

        $this->siteAttribute->setItem($item);

        $snapshotRender = $this->renderizer->renderBlocks($item->getId(), $item->getContent(), $this->siteAttribute->getAttributes());

        $item->setContent($snapshotRender, ItemInterface::SNAPSHOT_AFTER_RENDER_BLOCKS);

        $this->eventDispatcher->dispatch('spress.after_render_blocks', new Event\RenderEvent(
            $item,
            ItemInterface::SNAPSHOT_AFTER_RENDER_BLOCKS,
            ItemInterface::SNAPSHOT_PATH_RELATIVE_AFTER_CONVERT
        ));
    }

    private function renderPage(ItemInterface $item, array $layoutItems)
    {
        if ($this->isRenderizerExcluded($item) === true) {
            return;
        }

        $this->writeVvMessage(sprintf(
            'Rendering page for the item with id: "%s"',
            $item->getId()
        ));

        $this->eventDispatcher->dispatch('spress.before_render_page', new Event\RenderEvent(
            $item,
            ItemInterface::SNAPSHOT_AFTER_RENDER_BLOCKS,
            ItemInterface::SNAPSHOT_PATH_RELATIVE_AFTER_CONVERT
        ));

        $this->siteAttribute->setItem($item);

        $layoutId = $this->getLayoutAttribute($item);
        $snapshotPage = $this->renderizer->renderPage($item->getId(), $item->getContent(), $layoutId, $this->siteAttribute->getAttributes());
        $item->setContent($snapshotPage, ItemInterface::SNAPSHOT_AFTER_RENDER_PAGE);

        $this->eventDispatcher->dispatch('spress.after_render_page', new Event\RenderEvent(
            $item,
            ItemInterface::SNAPSHOT_AFTER_RENDER_PAGE,
            ItemInterface::SNAPSHOT_PATH_RELATIVE_AFTER_CONVERT
        ));

        if ($this->dependencyResolver != null && $layoutId !== null) {
            $layoutPath = ($layoutItems[$layoutId])->getPath(ItemInterface::SNAPSHOT_PATH_RELATIVE);
            $itemPath = $item->getPath(ItemInterface::SNAPSHOT_PATH_RELATIVE);

            $this->dependencyResolver->registerDependency($layoutPath, $itemPath);
        }
    }

    private function getCollectionAttributes(CollectionInterface $collection)
    {
        $result = $collection->getAttributes();

        $result['path'] = $collection->getPath();

        return $result;
    }

    private function configureTimezone($timezone)
    {
        if (is_string($timezone) === false) {
            throw new \RuntimeException('Invalid timezone. Expected a string value.');
        }

        if (strlen($timezone) == 0) {
            throw new \RuntimeException('Invalid timezone. Expected a non-empty value.');
        }

        date_default_timezone_set($timezone);
    }

    private function getLayoutAttribute(ItemInterface $item)
    {
        $attributes = $item->getAttributes();

        if (array_key_exists('layout', $attributes) === false) {
            return;
        }

        if (is_string($attributes['layout']) === false) {
            throw new AttributeValueException('Invalid value. Expected string.', 'layout', $item->getPath(ItemInterface::SNAPSHOT_PATH_RELATIVE));
        }

        if (strlen($attributes['layout']) == 0) {
            throw new AttributeValueException('Invalid value. Expected a non-empty string.', 'layout', $item->getPath(ItemInterface::SNAPSHOT_PATH_RELATIVE));
        }

        return $attributes['layout'];
    }

    private function isRenderizerExcluded(ItemInterface $item)
    {
        $attributes = $item->getAttributes();

        if (array_key_exists('avoid_renderizer', $attributes) === false) {
            return false;
        }

        if (is_bool($attributes['avoid_renderizer']) === false) {
            throw new AttributeValueException('Invalid value. Expected bolean.', 'avoid_renderizer', $item->getPath(ItemInterface::SNAPSHOT_PATH_RELATIVE));
        }

        return $attributes['avoid_renderizer'];
    }

    /**
     * @param string $key
     */
    private function escapeDot($key)
    {
        return str_replace('.', '[.]', $key);
    }

    /**
     * @param string|array $messasge
     */
    private function writeVMessage($message)
    {
        $this->io->write($message, true, IOInterface::VERBOSITY_VERBOSE);
    }

    /**
     * @param string|array $messasge
     */
    private function writeVvMessage($message)
    {
        $this->io->write($message, true, IOInterface::VERBOSITY_VERY_VERBOSE);
    }
}

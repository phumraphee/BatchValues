<?php
namespace BatchValues\Stdlib;

use BatchValues\Operation;
use Interop\Container\ContainerInterface;

/**
 * The operations and the menu that lists them.
 *
 * The menu is grouped by what people want to do, in the order they most
 * often need it. Each entry has a name and a one-line help (in the operation
 * class), plus an example "before → after" and other words to find it with
 * the menu's search box (here, so every text of the menu is in one place).
 */
class Operations
{
    const CLASSES = [
        Operation\AddValues::class,
        Operation\RemoveValues::class,
        Operation\ReplaceValues::class,
        Operation\FindReplaceValues::class,
        Operation\AffixValues::class,
        Operation\SplitValues::class,
        Operation\JoinValues::class,
        Operation\CleanValues::class,
        Operation\CopyValues::class,
        Operation\MoveValues::class,
        Operation\ValueVisibility::class,
        Operation\ValueLanguage::class,
        Operation\ConvertValues::class,
        Operation\SortValues::class,
        Operation\ResourceVisibility::class,
        Operation\ItemSetOpen::class,
        Operation\ResourceTemplateClass::class,
        Operation\ItemSets::class,
        Operation\Sites::class,
        Operation\ResourceOwner::class,
        Operation\ResourceThumbnail::class,
        Operation\MediaOrder::class,
        Operation\MediaVisibility::class,
        Operation\MediaDetails::class,
        Operation\MediaThumbnails::class,
        Operation\MediaRotate::class,
        Operation\SplitPdf::class,
        Operation\SplitItem::class,
        Operation\MediaRemove::class,
    ];

    /**
     * Menu groups: key => [title, sub-title, needs a property, operations].
     */
    const GROUPS = [
        'add_remove' => [
            'Add or remove values', // @translate
            'In one field (property)', // @translate
            true,
            ['add', 'remove'],
        ],
        'text' => [
            'Fix the text of values', // @translate
            'Spelling, words, spaces, lists', // @translate
            true,
            ['replace', 'find_replace', 'affix', 'split', 'join', 'clean'],
        ],
        'copy_move' => [
            'Copy or move to another field', // @translate
            'From one property to another', // @translate
            true,
            ['copy', 'move'],
        ],
        'settings' => [
            'Visibility, language, data type, order', // @translate
            'Of the values, not of the item', // @translate
            true,
            ['visibility', 'language', 'convert', 'sort'],
        ],
        'resource' => [
            'The %s themselves', // @translate
            'Visibility, template, item sets, sites, owner, thumbnail', // @translate
            false,
            ['resource_visibility', 'item_set_open', 'template', 'item_sets', 'sites', 'owner', 'thumbnail'],
        ],
        'media' => [
            'Media and files', // @translate
            'Order, visibility, details, thumbnails, PDF pages', // @translate
            false,
            ['media_order', 'media_visibility', 'media_details', 'media_thumbnails', 'media_rotate', 'split_pdf', 'split_item', 'media_remove'],
        ],
    ];

    /**
     * Example of each operation (English, shown as is) and search words.
     */
    const EXAMPLES = [
        'add' => ['Keywords: Painting → Painting, Abstract', 'new insert put tag keyword'],
        'remove' => ['Keywords: Painting, Draft → Painting', 'delete clear empty erase take out'],
        'replace' => ['“Bankok” → “Bangkok” (the whole value)', 'fix spelling typo change swap rename correct'],
        'find_replace' => ['“Chiangmai, Thailand” → “Chiang Mai, Thailand”', 'search part word regex regular expression typo spelling fix'],
        'affix' => ['“1961” → “c. 1961”', 'prefix suffix prepend append start end'],
        'split' => ['“Bangkok; Chiang Mai” → “Bangkok” + “Chiang Mai”', 'explode separate semicolon comma list divide'],
        'join' => ['“Bangkok” + “Chiang Mai” → “Bangkok; Chiang Mai”', 'merge combine concatenate glue'],
        'clean' => ['“ Bangkok ” → “Bangkok” · Bangkok, Bangkok → Bangkok', 'trim whitespace spaces duplicate dedupe invisible empty tidy'],
        'copy' => ['Subject: Bangkok → Subject: Bangkok + Coverage: Bangkok', 'duplicate property field'],
        'move' => ['Subject: Bangkok → Coverage: Bangkok', 'displace transfer property field'],
        'visibility' => ['Note: public → private', 'hide show private public value'],
        'language' => ['“Bangkok” → “Bangkok” @en · tha → th', 'lang code thai english'],
        'convert' => ['text “Painting” → the term “Painting” · “A-012” → the item A-012', 'datatype data type vocab term link uri resource literal identifier linked item'],
        'sort' => ['Chiang Mai, Bangkok → Bangkok, Chiang Mai', 'order alphabetical arrange'],
        'resource_visibility' => ['Item: private → public', 'publish hide show'],
        'item_set_open' => ['Item set: closed → open to additions', 'open close lock unlock openness additions item set'],
        'template' => ['Template: Photo → Artwork', 'resource template class type'],
        'item_sets' => ['+ Item set “Exhibition 2026”', 'collection set group'],
        'sites' => ['+ Site “Main site”', 'site publish'],
        'owner' => ['Owner: Admin → Staff A', 'user owner'],
        'thumbnail' => ['Thumbnail: (from media) → cover.jpg', 'image asset picture cover'],
        'media_order' => ['page-10, page-2 → page-2, page-10', 'sort arrange sequence position'],
        'media_visibility' => ['PDF: public → private', 'hide show media file'],
        'media_details' => ['Alt text: (empty) → “Rice Field”', 'alt text source filename mime media type lang accessibility'],
        'media_thumbnails' => ['(missing thumbnail) → thumbnail made from the file', 'rebuild regenerate derivative image'],
        'media_rotate' => ['Scan on its side → upright', 'rotate turn sideways upside down orientation 90 180 degrees straighten portrait landscape'],
        'split_pdf' => ['catalogue.pdf → catalogue-p001.jpg … p012.jpg', 'explode pdf pages images iiif jpeg'],
        'split_item' => ['1 item with 3 scans → 3 items with 1 scan each', 'explode separate item media'],
        'media_remove' => ['Item: 3 images + 1 text file → 3 images', 'delete media file erase'],
    ];

    /**
     * @var Operation\AbstractOperation[]
     */
    protected $operations = [];

    public function __construct(ContainerInterface $services)
    {
        $planner = $services->get(Planner::class);
        foreach (self::CLASSES as $class) {
            $operation = new $class($services, $planner);
            $this->operations[$operation->name()] = $operation;
        }
    }

    public function get(?string $name): ?Operation\AbstractOperation
    {
        return $this->operations[(string) $name] ?? null;
    }

    /**
     * @return Operation\AbstractOperation[]
     */
    public function forResourceType(string $resourceType): array
    {
        return array_filter($this->operations, function ($op) use ($resourceType) {
            return in_array($resourceType, $op->resourceTypes(), true) && $op->isAllowed($resourceType);
        });
    }

    /**
     * The menu for a resource type: groups with their operations, empty
     * groups left out.
     */
    public function menu(string $resourceType): array
    {
        $available = $this->forResourceType($resourceType);
        $menu = [];
        foreach (self::GROUPS as $key => [$title, $sub, $needsProperty, $names]) {
            $ops = [];
            foreach ($names as $name) {
                if (isset($available[$name])) {
                    $ops[$name] = $available[$name];
                }
            }
            if ($ops) {
                $menu[$key] = ['title' => $title, 'sub' => $sub, 'needs_property' => $needsProperty, 'operations' => $ops];
            }
        }
        return $menu;
    }

    public static function example(string $name): string
    {
        return self::EXAMPLES[$name][0] ?? '';
    }

    public static function keywords(string $name): string
    {
        return self::EXAMPLES[$name][1] ?? '';
    }
}

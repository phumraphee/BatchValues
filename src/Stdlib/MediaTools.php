<?php
namespace BatchValues\Stdlib;

use Interop\Container\ContainerInterface;
use Omeka\Api\Request;
use Omeka\Entity\Item;
use Omeka\Entity\Media;
use Omeka\Stdlib\ErrorStore;

/**
 * Work on media and files that is not a plain update of a resource:
 * thumbnails made again, media deleted, PDF pages made into images, an item
 * split into one item per media (and back).
 *
 * Every method returns what is written in the log ("resource" part of the
 * change row).
 */
class MediaTools
{
    /**
     * @var ContainerInterface
     */
    protected $services;

    public function __construct(ContainerInterface $services)
    {
        $this->services = $services;
    }

    /** The run being done: sent with every request, so other modules can tie
     * what they see to it (0.7.2). */
    protected $changeSetId;

    public function setChangeSetId(?int $setId): void
    {
        $this->changeSetId = $setId;
    }

    /** Options of every request this module makes. */
    protected function options(array $extra = []): array
    {
        return $extra + ['batchvalues' => true, 'batchvalues_change_set' => $this->changeSetId];
    }

    /**
     * The media of an item, in the order given, through the API (0.7.2): the
     * same way the operation "Reorder media" does it, so the change is a
     * normal item update that other modules see and can record.
     */
    protected function orderMedia(int $itemId, array $mediaIds): void
    {
        $content = ['o:media' => array_map(function ($mediaId) {
            return ['o:id' => (int) $mediaId];
        }, array_values(array_filter(array_map('intval', $mediaIds))))];
        if (!$content['o:media']) {
            return;
        }
        $this->services->get('Omeka\ApiManager')->update('items', $itemId, $content, [], $this->options(['isPartial' => true]));
    }

    public function apply(string $resourceType, int $id, string $kind, array $resource): array
    {
        $this->reattachIdentity();
        switch ($kind) {
            case 'rebuild_thumbnails':
                return ['media_thumbnails' => $this->rebuildThumbnails($resource['media_thumbnails'])];
            case 'restore_media':
                return ['media_restored' => $this->restoreMedia($resource['media_restored'])];
            case 'remove_media':
                return ['media_removed' => $this->removeMedia($resource['media_removed'])];
            case 'rotate_media':
                return ['media_rotate' => $this->rotateMedia($resource['media_rotate'])];
            case 'pdf_pages':
                return ['pdf_pages' => $this->pdfPages($resource['pdf_pages'])];
            case 'split_item':
                return ['split_item' => $this->splitItem($id, $resource['split_item'])];
            case 'unsplit_item':
                return ['unsplit_item' => $this->unsplitItem($id, $resource['split_item'])];
        }
        throw new \RuntimeException(sprintf('Unknown media work "%s".', $kind));
    }

    /**
     * After the entity manager was cleared (runs clear it between chunks),
     * the logged-in user is detached; a new resource would then fail to be
     * saved with it as owner.
     */
    protected function reattachIdentity(): void
    {
        $em = $this->services->get('Omeka\EntityManager');
        $auth = $this->services->get('Omeka\AuthenticationService');
        $identity = $auth->getIdentity();
        if ($identity && !$em->contains($identity)) {
            $user = $em->find(\Omeka\Entity\User::class, $identity->getId());
            if ($user) {
                $auth->getStorage()->write($user);
            }
        }
    }

    // ------------------------------------------------------------------
    // Files
    // ------------------------------------------------------------------

    /**
     * Which program can turn PDF pages into images on this server.
     */
    public static function pdfTool(): ?string
    {
        return self::pdfTools()[0] ?? null;
    }

    /**
     * Copy the original file of a media into a new temp file.
     *
     * @return \Omeka\File\TempFile|null
     */
    protected function originalTempFile(Media $media)
    {
        if (!$media->hasOriginal()) {
            return null;
        }
        $store = $this->services->get('Omeka\File\Store');
        $storagePath = 'original/' . $media->getFilename();
        $tempFile = $this->services->get('Omeka\File\TempFileFactory')->build();
        $ok = false;
        if ($store instanceof \Omeka\File\Store\Local) {
            $path = $store->getLocalPath($storagePath);
            $ok = is_readable($path) && @copy($path, $tempFile->getTempPath());
        } else {
            $data = @file_get_contents($store->getUri($storagePath));
            $ok = $data !== false && file_put_contents($tempFile->getTempPath(), $data) !== false;
        }
        if (!$ok) {
            $tempFile->delete();
            return null;
        }
        $tempFile->setStorageId($media->getStorageId());
        $tempFile->setSourceName((string) $media->getSource());
        return $tempFile;
    }

    protected function rebuildThumbnails(array $data): array
    {
        $em = $this->services->get('Omeka\EntityManager');
        $done = [];
        $failed = [];
        foreach (array_keys($data['media'] ?? []) as $mediaId) {
            $media = $em->find(Media::class, (int) $mediaId);
            $tempFile = $media ? $this->originalTempFile($media) : null;
            if (!$tempFile) {
                $failed[] = (int) $mediaId;
                continue;
            }
            try {
                $has = $tempFile->storeThumbnails();
                $media->setHasThumbnails($has);
                // The thumbnails are files on disk: without this, nothing in
                // the database changes and no history can show the run
                // (0.7.2).
                $mediaData = $media->getData();
                $mediaData = is_array($mediaData) ? $mediaData : [];
                $mediaData['o-module-batchvalues:thumbnails_made'] = gmdate('Y-m-d\TH:i:s\Z');
                $media->setData($mediaData);
                $em->flush();
                $has ? $done[] = (int) $mediaId : $failed[] = (int) $mediaId;
            } finally {
                $tempFile->delete();
            }
        }
        if ($done === [] && $failed) {
            throw new \RuntimeException('No thumbnail could be made (original file missing or not an image the thumbnailer reads).'); // @translate
        }
        return $data + ['rebuilt' => $done, 'failed' => $failed];
    }

    /**
     * Which programs can turn an image on this server (0.7.0).
     *
     * jpegtran turns a JPEG without encoding it again: the pixels stay
     * exactly as they are. ImageMagick's convert encodes the image again,
     * which loses a little for a JPEG (not for PNG or TIFF).
     */
    public static function rotateTools(): array
    {
        $tools = [];
        foreach (['jpegtran', 'convert'] as $tool) {
            if (trim((string) @shell_exec('command -v ' . $tool . ' 2>/dev/null')) !== '') {
                $tools[] = $tool;
            }
        }
        return $tools;
    }

    /** Media types this module turns. */
    const ROTATABLE = ['image/jpeg', 'image/png', 'image/tiff', 'image/webp'];

    /**
     * Turn the original file of image media by 90, 180 or 270 degrees
     * clockwise, then make their thumbnails again (0.7.0).
     *
     * The file itself is replaced, in place: there is no second copy of an
     * archive of this size. Turning it back by the same amount is what
     * "Cancel" does. A JPEG is turned with jpegtran when the server has it,
     * so nothing is encoded again; otherwise, and for the other kinds, with
     * ImageMagick, keeping the quality the file was saved with.
     */
    protected function rotateMedia(array $data): array
    {
        $degrees = (int) ($data['degrees'] ?? 0);
        if (!in_array($degrees, [90, 180, 270], true)) {
            throw new \RuntimeException('The turn must be 90, 180 or 270 degrees.'); // @translate
        }
        $em = $this->services->get('Omeka\EntityManager');
        $store = $this->services->get('Omeka\File\Store');
        $tools = self::rotateTools();
        if (!in_array('convert', $tools, true) && !in_array('jpegtran', $tools, true)) {
            throw new \RuntimeException('This server has neither jpegtran nor ImageMagick (convert), so images cannot be turned.'); // @translate
        }
        $done = [];
        $failed = [];
        $notes = [];
        foreach ($data['media'] ?? [] as $mediaId => $snapshot) {
            $media = $em->find(Media::class, (int) $mediaId);
            if (!$media || !$media->hasOriginal() || !in_array((string) $media->getMediaType(), self::ROTATABLE, true)) {
                $failed[] = (int) $mediaId;
                continue;
            }
            $tempFile = $this->originalTempFile($media);
            if (!$tempFile) {
                $failed[] = (int) $mediaId;
                continue;
            }
            $path = $tempFile->getTempPath();
            $out = $path . '-turned';
            try {
                $result = $this->turnFile($path, $out, (string) $media->getMediaType(), $degrees, $tools);
                if (!$result['ok']) {
                    $failed[] = (int) $mediaId;
                    if ($result['note']) {
                        $notes[] = $result['note'];
                    }
                    continue;
                }
                // The turned file takes the place of the original, under the
                // same name, then the thumbnails are made from it again.
                @unlink($path);
                @rename($out, $path);
                $tempFile->store('original', (string) $media->getExtension());
                $has = $tempFile->storeThumbnails();
                $media->setHasThumbnails($has);
                $media->setSha256($tempFile->getSha256());
                $media->setSize($tempFile->getSize());
                $em->flush();
                $done[(int) $mediaId] = [
                    'degrees' => $degrees,
                    'tool' => $result['tool'],
                    'lossless' => $result['lossless'],
                    'sha256' => ['before' => $snapshot['sha256'] ?? null, 'after' => $tempFile->getSha256()],
                    'size' => ['before' => $snapshot['size'] ?? null, 'after' => $tempFile->getSize()],
                ];
            } finally {
                @unlink($out);
                $tempFile->delete();
            }
        }
        if (!$done) {
            throw new \RuntimeException($notes
                ? implode(' ', array_unique($notes))
                : 'No image could be turned (no original file, or not a kind this module turns).'); // @translate
        }
        return $data + ['turned' => $done, 'failed' => $failed, 'note' => $notes ? implode(' ', array_unique($notes)) : null];
    }

    /**
     * One file, turned into $out.
     *
     * @return array ['ok' => bool, 'tool' => string, 'lossless' => bool, 'note' => ?string]
     */
    protected function turnFile(string $in, string $out, string $mediaType, int $degrees, array $tools): array
    {
        $orientation = self::exifOrientation($in);
        if ($mediaType === 'image/jpeg' && in_array('jpegtran', $tools, true) && in_array($orientation, [0, 1], true)) {
            $command = sprintf(
                'jpegtran -rotate %d -copy all -outfile %s %s 2>&1',
                $degrees,
                escapeshellarg($out),
                escapeshellarg($in)
            );
            @shell_exec($command);
            if (is_file($out) && filesize($out) > 0) {
                return ['ok' => true, 'tool' => 'jpegtran', 'lossless' => true, 'note' => null];
            }
        }
        if (!in_array('convert', $tools, true)) {
            return ['ok' => false, 'tool' => '', 'lossless' => false, 'note' => 'This server only has jpegtran, which turns JPEG files only.']; // @translate
        }
        // An image whose EXIF says it is already turned: the turn of the EXIF
        // is put into the pixels first (-auto-orient), so what people see is
        // what is saved, and the EXIF turn is reset by ImageMagick.
        $quality = $mediaType === 'image/jpeg' ? self::jpegQuality($in) : 0;
        $command = sprintf(
            'convert %s%s -rotate %d%s %s 2>&1',
            escapeshellarg($in),
            $orientation > 1 ? ' -auto-orient' : '',
            $degrees,
            $quality ? ' -quality ' . $quality : '',
            escapeshellarg($out)
        );
        @shell_exec($command);
        if (is_file($out) && filesize($out) > 0) {
            return ['ok' => true, 'tool' => 'convert', 'lossless' => $mediaType !== 'image/jpeg', 'note' => null];
        }
        return ['ok' => false, 'tool' => 'convert', 'lossless' => false, 'note' => 'ImageMagick could not turn one of the files.']; // @translate
    }

    /** 0 when the file has no EXIF turn. */
    public static function exifOrientation(string $path): int
    {
        if (!function_exists('exif_read_data')) {
            return 0;
        }
        $data = @exif_read_data($path);
        return (int) ($data['Orientation'] ?? 0);
    }

    /** The quality a JPEG was saved with, so it is saved again with the same. */
    protected static function jpegQuality(string $path): int
    {
        $q = (int) trim((string) @shell_exec('identify -format %Q ' . escapeshellarg($path) . ' 2>/dev/null'));
        return ($q >= 50 && $q <= 100) ? $q : 92;
    }

    protected function removeMedia(array $data): array
    {
        $api = $this->services->get('Omeka\ApiManager');
        $em = $this->services->get('Omeka\EntityManager');
        $connection = $this->services->get('Omeka\Connection');
        $keep = (bool) $this->services->get('Omeka\Settings')->get('batchvalues_keep_removed_files', true);
        $removed = [];
        foreach ($data['media'] ?? [] as $mediaId => $snapshot) {
            if (!$connection->fetchOne('SELECT 1 FROM media WHERE id = ?', [(int) $mediaId])) {
                continue;
            }
            if ($keep) {
                // The file is put aside before the media goes, so Cancel can
                // put the media back (0.7.2).
                $media = $em->find(Media::class, (int) $mediaId);
                $kept = $media ? $this->keepFile($media) : null;
                if ($kept !== null) {
                    $snapshot['kept_file'] = $kept;
                }
            }
            $api->delete('media', (int) $mediaId, [], $this->options());
            $removed[(int) $mediaId] = $snapshot;
        }
        if (!$removed) {
            throw new \RuntimeException('The media were already gone.'); // @translate
        }
        return ['media' => $removed] + $data;
    }

    /**
     * Which programs can turn PDF pages into images on this server.
     */
    public static function pdfTools(): array
    {
        $tools = [];
        foreach (['pdftoppm', 'convert'] as $tool) {
            if (trim((string) @shell_exec('command -v ' . $tool . ' 2>/dev/null')) !== '') {
                $tools[] = $tool;
            }
        }
        return $tools;
    }

    /**
     * Make one image per page of the PDF media of an item, each added right
     * after its PDF. The PDFs are kept. Every image is created through the
     * API (as an uploaded file would be), so other modules see a new media.
     */
    protected function pdfPages(array $data): array
    {
        $pdfs = $data['pdfs'] ?? (isset($data['pdf']) ? [['pdf' => $data['pdf'], 'label' => $data['label'] ?? null]] : []);
        $itemId = (int) ($data['item_id'] ?? 0);
        $done = [];
        $allCreated = [];
        try {
            foreach ($pdfs as $pdfData) {
                $created = $this->pdfToImages((int) $pdfData['pdf'], (int) ($data['dpi'] ?? 150), (string) ($data['tool'] ?? 'auto'));
                $allCreated = array_merge($allCreated, $created);
                $done[] = $pdfData + ['created' => $created, 'pages' => count($created)];
            }
        } catch (\Throwable $e) {
            // Leave nothing half made.
            $this->deleteMediaIds($allCreated);
            throw $e;
        }
        if ($itemId) {
            $this->touch('items', $itemId);
        }
        unset($data['pdf'], $data['label']);
        return ['pdfs' => $done] + $data;
    }

    /**
     * @return int[] Ids of the media made, in page order.
     */
    protected function pdfToImages(int $pdfId, int $dpi, string $tool): array
    {
        $em = $this->services->get('Omeka\EntityManager');
        $api = $this->services->get('Omeka\ApiManager');
        $connection = $this->services->get('Omeka\Connection');
        $pdf = $em->find(Media::class, $pdfId);
        if (!$pdf) {
            throw new \RuntimeException('The PDF media no longer exists.'); // @translate
        }
        $tools = self::pdfTools();
        if ($tool === 'auto' || !in_array($tool, $tools, true)) {
            $tool = $tools[0] ?? null;
        }
        if (!$tool) {
            throw new \RuntimeException('Neither pdftoppm (poppler-utils) nor ImageMagick is installed on the server.'); // @translate
        }
        $tempFile = $this->originalTempFile($pdf);
        if (!$tempFile) {
            throw new \RuntimeException('The PDF file could not be read.'); // @translate
        }
        $dir = \BatchValues\Media\LocalFileIngester::tempRoot($this->services) . '/batchvalues-pdf-' . bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0700)) {
            $tempFile->delete();
            throw new \RuntimeException('No temporary folder for the pages.'); // @translate
        }
        $dpi = max(50, min(600, $dpi));
        $input = $tempFile->getTempPath();
        if ($tool === 'pdftoppm') {
            $command = sprintf('pdftoppm -jpeg -r %d %s %s 2>&1', $dpi, escapeshellarg($input), escapeshellarg($dir . '/page'));
        } else {
            $command = sprintf('convert -density %d %s -quality 90 %s 2>&1', $dpi, escapeshellarg($input), escapeshellarg($dir . '/page-%04d.jpg'));
        }
        $output = [];
        $code = 0;
        exec($command, $output, $code);
        $tempFile->delete();
        $pages = glob($dir . '/page*.jpg') ?: [];
        natsort($pages);
        $pages = array_values($pages);
        $cleanup = function () use ($dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        };
        if ($code !== 0 || !$pages) {
            $cleanup();
            throw new \RuntimeException(trim('The pages could not be made. ' . implode(' ', array_slice($output, 0, 3)))); // @translate
        }
        $itemId = (int) $pdf->getItem()->getId();
        $isPublic = $pdf->isPublic();
        $base = pathinfo((string) $pdf->getSource(), PATHINFO_FILENAME) ?: ('media-' . $pdf->getId());
        $created = [];
        try {
            foreach ($pages as $n => $page) {
                $media = $api->create('media', [
                    'o:item' => ['o:id' => $itemId],
                    'o:ingester' => \BatchValues\Media\LocalFileIngester::NAME,
                    'batchvalues_path' => $page,
                    'o:source' => sprintf('%s-p%03d.jpg', $base, $n + 1),
                    'o:is_public' => $isPublic,
                ], [], $this->options(['responseContent' => 'resource']))->getContent();
                $created[] = (int) $media->getId();
            }
        } catch (\Throwable $e) {
            $this->deleteMediaIds($created);
            $cleanup();
            throw $e;
        }
        $cleanup();
        // Right after the PDF, in page order.
        $ids = array_map('intval', $connection->fetchFirstColumn('SELECT id FROM media WHERE item_id = ? ORDER BY position, id', [$itemId]));
        $others = array_values(array_diff($ids, $created));
        $at = array_search($pdfId, $others, true);
        $order = array_merge(array_slice($others, 0, $at + 1), $created, array_slice($others, $at + 1));
        $this->orderMedia($itemId, $order);
        $em->clear();
        $this->reattachIdentity();
        return $created;
    }

    protected function deleteMediaIds(array $ids): void
    {
        $api = $this->services->get('Omeka\ApiManager');
        foreach ($ids as $mediaId) {
            try {
                $api->delete('media', (int) $mediaId);
            } catch (\Throwable $e) {
            }
        }
    }

    /** Where files of deleted media are put aside, under Omeka's files. */
    public const KEPT_DIR = 'batchvalues-removed';

    public static function keptRoot(): string
    {
        return rtrim(OMEKA_PATH, '/') . '/files/' . self::KEPT_DIR;
    }

    /**
     * Copy the original file of a media out of the store, before the media is
     * deleted, and give back its name in the kept folder (0.7.2).
     */
    protected function keepFile(Media $media): ?string
    {
        if (!$media->hasOriginal()) {
            return null;
        }
        $root = self::keptRoot();
        if (!is_dir($root) && !@mkdir($root, 0775, true)) {
            return null;
        }
        $name = $media->getId() . '-' . $media->getStorageId() . '.' . $media->getExtension();
        $target = $root . '/' . $name;
        $store = $this->services->get('Omeka\File\Store');
        $storagePath = 'original/' . $media->getFilename();
        if ($store instanceof \Omeka\File\Store\Local) {
            $source = $store->getLocalPath($storagePath);
            $ok = is_readable($source) && @copy($source, $target);
        } else {
            $bytes = @file_get_contents($store->getUri($storagePath));
            $ok = $bytes !== false && @file_put_contents($target, $bytes) !== false;
        }
        return $ok ? $name : null;
    }

    /**
     * Put deleted media back from the files kept aside: the file itself, its
     * values, its details and its place in the item (0.7.2).
     */
    protected function restoreMedia(array $data): array
    {
        $api = $this->services->get('Omeka\ApiManager');
        $connection = $this->services->get('Omeka\Connection');
        $restored = [];
        $failed = [];
        $notes = [];
        $itemId = (int) ($data['item_id'] ?? 0);
        foreach ($data['media'] ?? [] as $oldId => $snapshot) {
            $kept = (string) ($snapshot['kept_file'] ?? '');
            $path = $kept === '' ? null : self::keptRoot() . '/' . basename($kept);
            if (!$path || !is_file($path)) {
                $failed[] = (int) $oldId;
                $notes[] = 'The file of a deleted media is not in the kept folder any more.'; // @translate
                continue;
            }
            $item = (int) ($snapshot['item_id'] ?? $itemId);
            if (!$item || !$connection->fetchOne('SELECT 1 FROM item WHERE id = ?', [$item])) {
                $failed[] = (int) $oldId;
                $notes[] = 'The item that held a deleted media is gone.'; // @translate
                continue;
            }
            $content = [
                'o:item' => ['o:id' => $item],
                'o:ingester' => \BatchValues\Media\LocalFileIngester::NAME,
                'batchvalues_path' => $path,
                'o:source' => (string) ($snapshot['source'] ?? basename($kept)),
                'o:is_public' => !empty($snapshot['is_public']),
            ];
            $media = $api->create('media', $content, [], $this->options(['responseContent' => 'resource']))->getContent();
            $newId = (int) $media->getId();
            $fields = array_filter([
                'source' => $snapshot['source'] ?? null,
                'media_type' => $snapshot['media_type'] ?? null,
                'alt_text' => $snapshot['alt_text'] ?? null,
                'lang' => $snapshot['lang'] ?? null,
            ], function ($v) {
                return $v !== null && $v !== '';
            });
            $update = [];
            if (!empty($snapshot['values'])) {
                $update['batchvalues_restore'] = $snapshot['values'];
            }
            if ($fields) {
                $update['batchvalues_media_fields'] = [$newId => $fields];
            }
            if ($update) {
                $api->update('media', $newId, $update, [], $this->options(['isPartial' => true, 'collectionAction' => 'append']));
            }
            $restored[(int) $oldId] = $newId;
        }
        if (!$restored) {
            throw new \RuntimeException($notes ? implode(' ', array_unique($notes)) : 'No deleted media could be put back.'); // @translate
        }
        // Back in the order they had: the restored ones at their old place.
        $byItem = [];
        foreach ($data['media'] ?? [] as $oldId => $snapshot) {
            if (isset($restored[$oldId])) {
                $byItem[(int) ($snapshot['item_id'] ?? $itemId)][(int) ($snapshot['position'] ?? 0)] = $restored[$oldId];
            }
        }
        foreach ($byItem as $item => $places) {
            $ids = array_map('intval', $connection->fetchFirstColumn('SELECT id FROM media WHERE item_id = ? ORDER BY position, id', [$item]));
            $ids = array_values(array_diff($ids, array_values($places)));
            ksort($places);
            foreach ($places as $position => $newId) {
                $at = max(0, min(count($ids), $position > 0 ? $position - 1 : count($ids)));
                array_splice($ids, $at, 0, [$newId]);
            }
            $this->orderMedia($item, $ids);
        }
        return $data + ['restored' => $restored, 'failed' => $failed, 'note' => $notes ? implode(' ', array_unique($notes)) : null];
    }

    /**
     * An empty update through the API, so the modified date, the full-text
     * index and other modules see the change.
     */
    protected function touch(string $resourceName, int $id): void
    {
        $this->services->get('Omeka\ApiManager')->update($resourceName, $id, [], [], $this->options(['isPartial' => true]));
    }

    // ------------------------------------------------------------------
    // Split an item into one item per media
    // ------------------------------------------------------------------

    /**
     * The first media stays with the item. Each other media goes to a new
     * item holding a copy of the item's values (same template, class,
     * visibility, owner, item sets and sites).
     */
    protected function splitItem(int $itemId, array $data): array
    {
        $em = $this->services->get('Omeka\EntityManager');
        $api = $this->services->get('Omeka\ApiManager');
        $planner = $this->services->get(Planner::class)->setResourceType('items');
        $item = $em->find(Item::class, $itemId);
        if (!$item) {
            throw new \RuntimeException('The item no longer exists.'); // @translate
        }
        $mediaIds = array_map('intval', $data['move'] ?? []);
        $current = [];
        foreach ($item->getMedia() as $media) {
            $current[(int) $media->getId()] = $media;
        }
        foreach ($mediaIds as $mediaId) {
            if (!isset($current[$mediaId])) {
                throw new \RuntimeException('The media of this item changed since the preview; run it again.'); // @translate
            }
        }
        $info = $planner->resourceInfo([$itemId])[$itemId];
        $rows = $planner->valuesByResource([$itemId], null)[$itemId] ?? [];
        $snapshots = [];
        foreach ($rows as $row) {
            $snapshot = Planner::snapshotFromRow($row);
            unset($snapshot['value_id']);
            $snapshots[] = $snapshot;
        }
        $titlePropertyId = $this->titlePropertyId($info['template_id']);
        $itemSets = array_map(function ($i) {
            return ['o:id' => $i];
        }, $planner->links([$itemId], 'item_item_set', 'item_set_id')[$itemId] ?? []);
        $sites = array_map(function ($i) {
            return ['o:id' => $i];
        }, $planner->links([$itemId], 'item_site', 'site_id')[$itemId] ?? []);

        $made = [];
        try {
            $this->splitEach($item, $itemId, $mediaIds, $data, $info, $snapshots, $titlePropertyId, $itemSets, $sites, $made);
        } catch (\Throwable $e) {
            // Leave the item as it was: media back, new items deleted.
            if ($made) {
                try {
                    $this->unsplitItem($itemId, ['items' => $made]);
                } catch (\Throwable $ignore) {
                }
            }
            throw $e;
        }
        $this->renumber($itemId);
        $this->touch('items', $itemId);
        foreach (array_keys($made) as $newId) {
            $this->touch('items', $newId);
        }
        return $data + ['items' => $made];
    }

    protected function splitEach(Item $item, int $itemId, array $mediaIds, array $data, array $info, array $snapshots, int $titlePropertyId, array $itemSets, array $sites, array &$made): void
    {
        $em = $this->services->get('Omeka\EntityManager');
        $api = $this->services->get('Omeka\ApiManager');
        $planner = $this->services->get(Planner::class);
        $mode = $data['title_mode'] ?? 'copy';
        foreach ($mediaIds as $mediaId) {
            $values = $snapshots;
            if (in_array($mode, ['append', 'update', 'replace'], true)) {
                // The media's own values: added to the item's, used instead of
                // the item's for the same properties, or alone.
                $mediaValues = [];
                foreach ($planner->valuesByResource([$mediaId], null)[$mediaId] ?? [] as $row) {
                    $snapshot = Planner::snapshotFromRow($row);
                    unset($snapshot['value_id']);
                    $mediaValues[] = $snapshot;
                }
                if ($mediaValues) {
                    if ($mode === 'append') {
                        $values = array_merge($values, $mediaValues);
                    } elseif ($mode === 'update') {
                        $mediaProperties = array_fill_keys(array_map('intval', array_column($mediaValues, 'property_id')), true);
                        $values = array_merge(array_values(array_filter($values, function ($s) use ($mediaProperties) {
                            return !isset($mediaProperties[(int) $s['property_id']]);
                        })), $mediaValues);
                    } else {
                        $values = $mediaValues;
                    }
                    // Title first, as on a new item.
                    usort($values, function ($a, $b) use ($titlePropertyId) {
                        return ((int) $b['property_id'] === $titlePropertyId) <=> ((int) $a['property_id'] === $titlePropertyId);
                    });
                }
            }
            if ($mode === 'media_title') {
                $mediaTitle = [];
                foreach ($planner->valuesByResource([$mediaId], $titlePropertyId)[$mediaId] ?? [] as $row) {
                    $snapshot = Planner::snapshotFromRow($row);
                    unset($snapshot['value_id']);
                    $mediaTitle[] = $snapshot;
                }
                if ($mediaTitle) {
                    $values = array_merge(array_values(array_filter($values, function ($s) use ($titlePropertyId) {
                        return (int) $s['property_id'] !== $titlePropertyId;
                    })), $mediaTitle);
                    // Title first, as on a new item.
                    usort($values, function ($a, $b) use ($titlePropertyId) {
                        return ((int) $b['property_id'] === $titlePropertyId) <=> ((int) $a['property_id'] === $titlePropertyId);
                    });
                }
            }
            $content = [
                'o:is_public' => $info['is_public'],
                'o:resource_template' => $info['template_id'] ? ['o:id' => $info['template_id']] : null,
                'o:resource_class' => $info['class_id'] ? ['o:id' => $info['class_id']] : null,
                'o:item_set' => $itemSets,
                'o:site' => $sites,
            ];
            // Made in two steps: Omeka replaces the values when it creates a
            // resource, so the values are put in by an update, together with
            // the template (whose required properties are then there).
            $template = $content['o:resource_template'];
            unset($content['o:resource_template']);
            $newId = (int) $api->create('items', $content, [], $this->options(['responseContent' => 'resource']))->getContent()->getId();
            try {
                $api->update('items', $newId, [
                    'o:resource_template' => $template,
                    // Values exactly as they are (data types, visibility, annotations).
                    'batchvalues_restore' => $values,
                ], [], $this->options(['isPartial' => true, 'collectionAction' => 'append']));
            } catch (\Throwable $e) {
                $api->delete('items', $newId);
                throw $e;
            }
            $newItem = $em->find(Item::class, $newId);
            if ($info['owner_id']) {
                $newItem->setOwner($em->getReference(\Omeka\Entity\User::class, $info['owner_id']));
            }
            $media = $em->find(Media::class, $mediaId);
            $media->setItem($newItem);
            $media->setPosition(1);
            $em->flush();
            $made[$newId] = [$mediaId];
        }
    }

    /**
     * Put the media back into the item they came from and delete the items
     * made by the split, unless something was added to them since.
     */
    protected function unsplitItem(int $itemId, array $data): array
    {
        $em = $this->services->get('Omeka\EntityManager');
        $api = $this->services->get('Omeka\ApiManager');
        $item = $em->find(Item::class, $itemId);
        if (!$item) {
            throw new \RuntimeException('The original item no longer exists.'); // @translate
        }
        $position = 0;
        foreach ($item->getMedia() as $media) {
            $position = max($position, (int) $media->getPosition());
        }
        $done = [];
        $kept = [];
        foreach ($data['items'] ?? [] as $newId => $mediaIds) {
            $newItem = $em->find(Item::class, (int) $newId);
            if (!$newItem) {
                $kept[(int) $newId] = 'deleted since';
                continue;
            }
            $moved = [];
            foreach ($mediaIds as $mediaId) {
                $media = $em->find(Media::class, (int) $mediaId);
                if ($media && $media->getItem() && (int) $media->getItem()->getId() === (int) $newId) {
                    $media->setItem($item);
                    $media->setPosition(++$position);
                    $moved[] = (int) $mediaId;
                }
            }
            $em->flush();
            $left = (int) $this->services->get('Omeka\Connection')->fetchOne('SELECT COUNT(*) FROM media WHERE item_id = ?', [(int) $newId]);
            if ($left) {
                $kept[(int) $newId] = 'has other media';
            } else {
                $api->delete('items', (int) $newId);
            }
            $done[(int) $newId] = $moved;
        }
        if (!$done) {
            throw new \RuntimeException('Nothing to put back: the items made by the split are gone.'); // @translate
        }
        $this->touch('items', $itemId);
        return ['items' => $done, 'kept' => $kept];
    }

    /**
     * Positions 1, 2, 3… again after media were moved away (split).
     *
     * Through Doctrine, like the move itself: an API update with "o:media"
     * here would take the item's media collection as it is in memory, where
     * the media just moved are not yet gone, and would drop them (0.7.2).
     */
    protected function renumber(int $itemId): void
    {
        $em = $this->services->get('Omeka\EntityManager');
        $item = $em->find(Item::class, $itemId);
        if (!$item) {
            return;
        }
        $media = $item->getMedia()->toArray();
        usort($media, function ($a, $b) {
            return [(int) $a->getPosition(), (int) $a->getId()] <=> [(int) $b->getPosition(), (int) $b->getId()];
        });
        $position = 0;
        foreach ($media as $one) {
            $one->setPosition(++$position);
        }
        $em->flush();
    }

    public function titlePropertyId(?int $templateId): int
    {
        $connection = $this->services->get('Omeka\Connection');
        if ($templateId) {
            $id = $connection->fetchOne('SELECT title_property_id FROM resource_template WHERE id = ?', [$templateId]);
            if ($id) {
                return (int) $id;
            }
        }
        return (int) $connection->fetchOne("SELECT p.id FROM property p JOIN vocabulary v ON v.id = p.vocabulary_id WHERE v.prefix = 'dcterms' AND p.local_name = 'title'");
    }
}

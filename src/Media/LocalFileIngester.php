<?php
namespace BatchValues\Media;

use Interop\Container\ContainerInterface;
use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;
use Laminas\View\Renderer\PhpRenderer;

/**
 * Makes a media from a file Batch Values has just written in its own
 * temporary folder (page images of a PDF), through a normal API create, so
 * that the media is created, indexed and processed like an uploaded file.
 *
 * It accepts nothing else: the request must come from Batch Values (request
 * option, which the REST API cannot set) and the file must be in a folder
 * Batch Values made. It is not listed in the "Add media" menu of the item
 * form (see Module::hideIngester()), and the media gets the ingester
 * "upload", so afterwards it is an ordinary uploaded file.
 */
class LocalFileIngester implements IngesterInterface
{
    const NAME = 'batchvalues_file';

    /**
     * @var ContainerInterface
     */
    protected $services;

    public function __construct(ContainerInterface $services)
    {
        $this->services = $services;
    }

    public function getLabel()
    {
        return 'File made by Batch Values'; // @translate
    }

    public function getRenderer()
    {
        return 'file';
    }

    public function form(PhpRenderer $view, array $options = [])
    {
        return '';
    }

    public static function tempRoot(ContainerInterface $services): string
    {
        $config = $services->get('Config');
        return rtrim($config['temp_dir'] ?? sys_get_temp_dir(), '/');
    }

    public function ingest(Media $media, Request $request, ErrorStore $errorStore)
    {
        if (!$request->getOption('batchvalues')) {
            $errorStore->addError('o:ingester', 'This ingester is used by Batch Values only.'); // @translate
            return;
        }
        $data = $request->getContent();
        $path = realpath((string) ($data['batchvalues_path'] ?? ''));
        $root = realpath(self::tempRoot($this->services));
        // Pages made from a PDF, or a file kept aside when a media was
        // deleted and is being put back (0.7.2).
        $kept = realpath(\BatchValues\Stdlib\MediaTools::keptRoot());
        $fromTemp = $root && strpos((string) $path, $root . '/batchvalues-pdf-') === 0;
        $fromKept = $kept && strpos((string) $path, $kept . '/') === 0;
        if (!$path || (!$fromTemp && !$fromKept) || !is_file($path)) {
            $errorStore->addError('batchvalues_path', 'No such file made by Batch Values.'); // @translate
            return;
        }
        $tempFile = $this->services->get('Omeka\File\TempFileFactory')->build();
        if (!@copy($path, $tempFile->getTempPath())) {
            $tempFile->delete();
            $errorStore->addError('batchvalues_path', 'The file could not be read.'); // @translate
            return;
        }
        $sourceName = (string) ($data['o:source'] ?? basename($path));
        $tempFile->setSourceName($sourceName);
        $media->setSource($sourceName);
        // Afterwards it is an ordinary uploaded file.
        $media->setIngester('upload');
        $tempFile->mediaIngestFile($media, $request, $errorStore);
    }
}

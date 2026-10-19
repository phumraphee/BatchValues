<?php
namespace BatchValues\Operation;

use BatchValues\Stdlib\MediaTools;

/**
 * Make one image media per page of each PDF, added to the item right after
 * the PDF, so viewers that show images (IIIF, image galleries) can page
 * through it. The PDF is kept.
 */
class SplitPdf extends AbstractMediaOperation
{
    public function name(): string
    {
        return 'split_pdf';
    }

    public function label(): string
    {
        return 'Make page images from PDFs'; // @translate
    }

    public function help(): string
    {
        return 'Each page of a PDF becomes a JPEG image in the same item, right after the PDF. The PDF is kept.'; // @translate
    }

    public function formVars(array $ctx): array
    {
        return ['tool' => MediaTools::pdfTool(), 'tools' => MediaTools::pdfTools()];
    }

    public function syncLimit(int $default): int
    {
        return min($default, 3);
    }

    public function parse(array $post, array $ctx): array
    {
        $errors = [];
        $tools = MediaTools::pdfTools();
        if (!$tools) {
            $errors[] = $this->translate('This server has neither pdftoppm (poppler-utils) nor ImageMagick: PDF pages cannot be made into images here.');
        }
        $dpi = in_array((int) ($post['dpi'] ?? 150), [100, 150, 200, 300], true) ? (int) $post['dpi'] : 150;
        $which = (in_array($post['which'] ?? 'all', ['all', 'first', 'last'], true) ? ($post['which'] ?? 'all') : 'all');
        $tool = (in_array($post['tool'] ?? 'auto', $tools, true) ? ($post['tool'] ?? 'auto') : 'auto');
        $whichLabels = [
            'all' => 'Every PDF of each item', // @translate
            'first' => 'The first PDF of each item', // @translate
            'last' => 'The last PDF of each item', // @translate
        ];
        return ['params' => [
            'dpi' => $dpi,
            'which' => $which,
            'tool' => $tool,
            'skip_done' => !empty($post['skip_done']),
            'display' => [
                ['label' => 'PDFs', 'text' => $whichLabels[$which]], // @translate
                ['label' => 'Resolution', 'text' => $dpi . ' dpi'], // @translate
            ],
        ], 'errors' => $errors];
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        return $this->planMedia($ids, $ctx, function (array $rows, int $id) use ($params) {
            $pdfs = array_values(array_filter($rows, function ($row) {
                return $row['media_type'] === 'application/pdf' && $row['has_original'];
            }));
            if (!$pdfs) {
                return ['outcome' => 'skipped_no_media'];
            }
            $which = $params['which'] ?? 'first';
            if ($which === 'first') {
                $pdfs = [$pdfs[0]];
            } elseif ($which === 'last') {
                $pdfs = [end($pdfs)];
            }
            $todo = [];
            foreach ($pdfs as $pdf) {
                if (!empty($params['skip_done']) && $this->pagesExist($pdf)) {
                    continue;
                }
                $todo[] = ['pdf' => $pdf['id'], 'label' => self::mediaLabel($pdf)];
            }
            if (!$todo) {
                return ['outcome' => 'skipped_same', 'message' => $this->translate('Page images of these PDFs are there already.')];
            }
            return ['outcome' => 'changed', 'custom' => 'pdf_pages', 'resource' => ['pdf_pages' => [
                'pdfs' => $todo,
                'item_id' => $pdfs[0]['item_id'],
                'dpi' => (int) ($params['dpi'] ?? 150),
                'tool' => $params['tool'] ?? 'auto',
            ]]];
        });
    }

    /**
     * Page images made before have the file name "<pdf name>-p001.jpg".
     */
    protected function pagesExist(array $pdf): bool
    {
        $base = pathinfo((string) $pdf['source'], PATHINFO_FILENAME) ?: ('media-' . $pdf['id']);
        return (bool) $this->services->get('Omeka\Connection')->fetchOne(
            'SELECT 1 FROM media WHERE item_id = ? AND source = ?',
            [$pdf['item_id'], $base . '-p001.jpg']
        );
    }
}

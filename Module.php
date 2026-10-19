<?php
namespace BatchValues;

use Laminas\EventManager\Event;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\View\Renderer\PhpRenderer;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ModuleManager\ModuleEvent;
use Laminas\ModuleManager\ModuleManager;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Module\AbstractModule;
use BatchValues\Stdlib\ChangeLog;
use BatchValues\Stdlib\Planner;

class Module extends AbstractModule
{
    /**
     * The name the log gives a save made on Omeka's own form with the buttons
     * of this module (0.11.0). It is not one of the operations: it cannot be
     * chosen, and it cannot be cancelled from here.
     */
    const FORM_EDIT = 'form_edit';

    /**
     * The state of the fields those buttons touched, kept between
     * api.update.pre and api.update.post.
     *
     * @var array
     */
    protected $formEdit = [];

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function init(ModuleManager $moduleManager)
    {
        $moduleManager->getEventManager()->attach(
            ModuleEvent::EVENT_MERGE_CONFIG,
            [$this, 'placeMenu']
        );
    }

    /**
     * Put the menu entry right under another module's entry with the navigation key "tcf"
     * when another module provides it, whatever order the modules load in;
     * otherwise it stays at the end of the RESOURCES section.
     */
    public function placeMenu(ModuleEvent $event)
    {
        $listener = $event->getConfigListener();
        $config = $listener->getMergedConfig(false);
        $nav = $config['navigation']['AdminResource'] ?? [];
        if (!isset($nav['batchvalues'], $nav['tcf'])) {
            return;
        }
        $entry = $nav['batchvalues'];
        unset($nav['batchvalues']);
        $placed = [];
        foreach ($nav as $key => $page) {
            $placed[$key] = $page;
            if ($key === 'tcf') {
                $placed['batchvalues'] = $entry;
            }
        }
        $config['navigation']['AdminResource'] = $placed;
        $listener->setMergedConfig($config);
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        // Same roles that may batch edit items in core. Each item update is
        // still authorized individually by the API.
        $acl->allow(
            ['editor', 'site_admin', 'global_admin'],
            Controller\Admin\IndexController::class
        );
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $hidden = array_map('intval', (array) $settings->get('batchvalues_hidden_vocabularies', []));
        $syncLimit = (int) $settings->get('batchvalues_sync_limit', 50);
        $vocabularies = $services->get('Omeka\Connection')->fetchAllAssociative(
            'SELECT v.id, v.label, v.prefix, COUNT(p.id) AS properties FROM vocabulary v LEFT JOIN property p ON p.vocabulary_id = v.id GROUP BY v.id, v.label, v.prefix ORDER BY v.label'
        );
        return $renderer->partial('batch-values/admin/config-form', [
            'vocabularies' => $vocabularies,
            'hidden' => $hidden,
            'syncLimit' => $syncLimit,
            'hideCore' => (bool) $settings->get('batchvalues_hide_core_batch_edit', false),
            'browsePeek' => (bool) $settings->get('batchvalues_browse_peek', true),
            'fixCancel' => (bool) $settings->get('batchvalues_fix_cancel', true),
            'keepRemoved' => (bool) $settings->get('batchvalues_keep_removed_files', true),
            'saveTrim' => (bool) $settings->get('batchvalues_save_trim', false),
            'saveDedupe' => (bool) $settings->get('batchvalues_save_dedupe', false),
            'saveLinkType' => (bool) $settings->get('batchvalues_save_link_type', true),
            'formConfirm' => (string) $settings->get('batchvalues_form_confirm', 'both'),
            'formTools' => (bool) $settings->get('batchvalues_form_tools', true),
            'formLog' => (bool) $settings->get('batchvalues_form_log', true),
            'accessionProperty' => (string) $settings->get('batchvalues_accession_property', ''),
            'accessionYears' => (string) $settings->get('batchvalues_accession_placeholder_years', ''),
            'accessionShapes' => (string) $settings->get('batchvalues_accession_shapes', ''),
        ]);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $post = $controller->getRequest()->getPost()->toArray();
        // The shapes are checked before anything is saved, so a mistake there leaves every
        // setting as it was rather than half of them changed.
        $shapes = trim((string) ($post['batchvalues_accession_shapes'] ?? ''));
        [, $errors] = \BatchValues\Stdlib\Planner::parseAccessionShapes($shapes);
        foreach ($errors as $error) {
            $controller->messenger()->addError($error);
        }
        if ($errors) {
            return false;
        }
        $hidden = array_values(array_unique(array_filter(array_map('intval', (array) ($post['batchvalues_hidden_vocabularies'] ?? [])))));
        $settings->set('batchvalues_hidden_vocabularies', $hidden);
        $settings->set('batchvalues_sync_limit', max(1, (int) ($post['batchvalues_sync_limit'] ?? 50)));
        $settings->set('batchvalues_hide_core_batch_edit', !empty($post['batchvalues_hide_core_batch_edit']));
        $settings->set('batchvalues_browse_peek', !empty($post['batchvalues_browse_peek']));
        $settings->set('batchvalues_fix_cancel', !empty($post['batchvalues_fix_cancel']));
        $settings->set('batchvalues_keep_removed_files', !empty($post['batchvalues_keep_removed_files']));
        $settings->set('batchvalues_save_trim', !empty($post['batchvalues_save_trim']));
        $settings->set('batchvalues_save_dedupe', !empty($post['batchvalues_save_dedupe']));
        $settings->set('batchvalues_save_link_type', !empty($post['batchvalues_save_link_type']));
        $settings->set('batchvalues_form_tools', !empty($post['batchvalues_form_tools']));
        $settings->set('batchvalues_form_log', !empty($post['batchvalues_form_log']));
        $settings->set('batchvalues_accession_property', trim((string) ($post['batchvalues_accession_property'] ?? '')));
        $settings->set('batchvalues_accession_placeholder_years', trim((string) ($post['batchvalues_accession_placeholder_years'] ?? '')));
        $settings->set('batchvalues_accession_shapes', $shapes);
        $confirm = (string) ($post['batchvalues_form_confirm'] ?? 'both');
        $settings->set('batchvalues_form_confirm', in_array($confirm, ['off', 'cancel', 'both'], true) ? $confirm : 'both');
        return true;
    }

    public function install(ServiceLocatorInterface $services)
    {
        $connection = $services->get('Omeka\Connection');
        foreach ($this->installSql() as $sql) {
            $connection->executeStatement($sql);
        }
    }

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $services)
    {
        $connection = $services->get('Omeka\Connection');
        if (version_compare($oldVersion, '0.5.0', '<')) {
            // "completed_with_errors" (21 characters) did not fit in 20.
            $connection->executeStatement('ALTER TABLE batch_values_change_set MODIFY operation VARCHAR(30) NOT NULL, MODIFY status VARCHAR(30) NOT NULL');
        }
        if (version_compare($oldVersion, '0.8.0', '<')) {
            // The order of each field before and after a change, so the
            // history page can show the two columns (0.8.0). Runs logged
            // before this stay as they are: the column is null for them.
            $has = $connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
                . " AND TABLE_NAME = 'batch_values_change' AND COLUMN_NAME = 'field_data'"
            );
            if (!$has) {
                $connection->executeStatement('ALTER TABLE batch_values_change ADD field_data LONGTEXT DEFAULT NULL');
            }
        }
    }

    public function uninstall(ServiceLocatorInterface $services)
    {
        $connection = $services->get('Omeka\Connection');
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $connection->executeStatement('DROP TABLE IF EXISTS batch_values_change');
        $connection->executeStatement('DROP TABLE IF EXISTS batch_values_change_set');
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $settings = $services->get('Omeka\Settings');
        $settings->delete('batchvalues_hidden_vocabularies');
        $settings->delete('batchvalues_sync_limit');
        $settings->delete('batchvalues_hide_core_batch_edit');
        $settings->delete('batchvalues_browse_peek');
        $settings->delete('batchvalues_fix_cancel');
        $settings->delete('batchvalues_keep_removed_files');
        $settings->delete('batchvalues_save_trim');
        $settings->delete('batchvalues_save_dedupe');
        $settings->delete('batchvalues_save_link_type');
        $settings->delete('batchvalues_form_confirm');
        $settings->delete('batchvalues_form_tools');
        $settings->delete('batchvalues_form_log');
        $settings->delete('batchvalues_accession_property');
        $settings->delete('batchvalues_accession_placeholder_years');
        $settings->delete('batchvalues_accession_shapes');
    }

    protected function installSql(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS batch_values_change_set (
    id INT AUTO_INCREMENT NOT NULL,
    operation VARCHAR(30) NOT NULL,
    resource_type VARCHAR(50) NOT NULL DEFAULT 'items',
    property_id INT DEFAULT NULL,
    property_term VARCHAR(190) DEFAULT NULL,
    property_label VARCHAR(255) DEFAULT NULL,
    reverses_id INT DEFAULT NULL,
    params LONGTEXT NOT NULL,
    selection LONGTEXT NOT NULL,
    status VARCHAR(30) NOT NULL,
    total INT NOT NULL DEFAULT 0,
    processed INT NOT NULL DEFAULT 0,
    changed INT NOT NULL DEFAULT 0,
    skipped INT NOT NULL DEFAULT 0,
    failed INT NOT NULL DEFAULT 0,
    owner_id INT DEFAULT NULL,
    owner_name VARCHAR(190) DEFAULT NULL,
    owner_email VARCHAR(190) DEFAULT NULL,
    job_id INT DEFAULT NULL,
    created DATETIME NOT NULL,
    finished DATETIME DEFAULT NULL,
    message LONGTEXT DEFAULT NULL,
    INDEX IDX_BV_SET_CREATED (created),
    INDEX IDX_BV_SET_REVERSES (reverses_id),
    INDEX IDX_BV_SET_OWNER (owner_id),
    INDEX IDX_BV_SET_PROPERTY (property_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS batch_values_change (
    id INT AUTO_INCREMENT NOT NULL,
    change_set_id INT NOT NULL,
    resource_id INT NOT NULL,
    resource_title LONGTEXT DEFAULT NULL,
    template_id INT DEFAULT NULL,
    outcome VARCHAR(30) NOT NULL,
    value_data LONGTEXT DEFAULT NULL,
    field_data LONGTEXT DEFAULT NULL,
    reverses_change_id INT DEFAULT NULL,
    message LONGTEXT DEFAULT NULL,
    created DATETIME NOT NULL,
    INDEX IDX_BV_CHANGE_SET (change_set_id, outcome),
    INDEX IDX_BV_CHANGE_RESOURCE (resource_id),
    INDEX IDX_BV_CHANGE_REVERSES (reverses_change_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL,
            // History must survive deleted users, properties and resources, so
            // only the set->change link is a hard constraint; user and property
            // are snapshotted (name, email, term, label) and nulled on delete.
            'ALTER TABLE batch_values_change ADD CONSTRAINT FK_BV_CHANGE_SET FOREIGN KEY (change_set_id) REFERENCES batch_values_change_set (id) ON DELETE CASCADE',
            'ALTER TABLE batch_values_change_set ADD CONSTRAINT FK_BV_SET_OWNER FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE SET NULL',
            'ALTER TABLE batch_values_change_set ADD CONSTRAINT FK_BV_SET_PROPERTY FOREIGN KEY (property_id) REFERENCES property (id) ON DELETE SET NULL',
        ];
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        // Precise value removal / restoration inside a normal API update, so
        // title, modified date, full-text index and other modules' listeners
        // all behave exactly as for a manual edit.
        foreach ([
            \Omeka\Api\Adapter\ItemAdapter::class,
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            \Omeka\Api\Adapter\MediaAdapter::class,
        ] as $adapter) {
            $sharedEventManager->attach($adapter, 'api.hydrate.pre', [$this, 'handleHydratePre']);
            $sharedEventManager->attach($adapter, 'api.create.pre', [$this, 'cleanOnSave']);
            $sharedEventManager->attach($adapter, 'api.update.pre', [$this, 'cleanOnSave']);
            // A save made from Omeka's own form after the buttons of this
            // module were used there: the fields they touched are written to
            // the log (0.11.0).
            $sharedEventManager->attach($adapter, 'api.update.pre', [$this, 'logFormEditPre'], -100);
            $sharedEventManager->attach($adapter, 'api.update.post', [$this, 'logFormEditPost'], -100);
        }

        // The ingester used to make page images from PDFs is not offered in
        // the "Add media" menu of the item form.
        $sharedEventManager->attach(
            \Omeka\Media\Ingester\Manager::class,
            'service.registered_names',
            function (Event $event) {
                // The params are an ArrayObject shared with the manager.
                $params = $event->getParams();
                $params['registered_names'] = array_values(array_diff((array) $params['registered_names'], [Media\LocalFileIngester::NAME]));
            }
        );

        // One "Add/remove values" entry in the batch actions of the browse
        // pages (and, when set, Omeka's own batch edit entries hidden).
        foreach ([
            'Omeka\Controller\Admin\Item' => 'items',
            'Omeka\Controller\Admin\ItemSet' => 'item_sets',
            'Omeka\Controller\Admin\Media' => 'media',
        ] as $controller => $resourceType) {
            $sharedEventManager->attach($controller, 'view.browse.before', function (Event $event) use ($resourceType) {
                $this->addBrowseBatchActions($event, $resourceType);
            });
            // Quick look from the pictures of linked resources on the show
            // pages (Related Item, Linked resources, media) (0.6.8).
            $sharedEventManager->attach($controller, 'view.show.after', function (Event $event) {
                $this->addShowPeek($event);
            });
            // Quick look from the thumbnails of the list (0.6.7).
            $sharedEventManager->attach($controller, 'view.browse.after', function (Event $event) use ($resourceType) {
                $this->addBrowsePeek($event, $resourceType);
            });
            // The add and edit forms: mark a value already in the same field.
            foreach (['view.add.after', 'view.edit.after'] as $viewEvent) {
                $sharedEventManager->attach($controller, $viewEvent, [$this, 'addFormDuplicateMarks']);
            }
        }
    }

    /**
     * Where "Cancel" on the add or edit form should go (0.7.1).
     *
     * Omeka's own Cancel is one step back in the browser's history. In a tab
     * that was opened on the form itself (the quick look's "Edit", or any
     * link opened in a new tab), there is no step back: nothing happens, or
     * the tab goes blank. This gives that button an address: the page of the
     * resource being edited, or the list when a new one is being added.
     */
    protected function cancelUrl($view): ?string
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        if (!$settings->get('batchvalues_fix_cancel', true)) {
            return null;
        }
        $params = $this->getServiceLocator()->get('Application')->getMvcEvent()->getRouteMatch();
        $controller = $params ? (string) $params->getParam('controller') : '';
        $short = ['Omeka\Controller\Admin\Item' => 'item', 'Omeka\Controller\Admin\ItemSet' => 'item-set', 'Omeka\Controller\Admin\Media' => 'media'][$controller] ?? null;
        if (!$short) {
            return null;
        }
        $id = (int) $params->getParam('id');
        return $id
            ? $view->url('admin/id', ['controller' => $short, 'action' => 'show', 'id' => $id])
            : $view->url('admin/default', ['controller' => $short, 'action' => 'browse']);
    }

    /**
     * Omeka's Items, Item sets and Media lists: a click on a thumbnail opens
     * the quick look of Batch Values (picture, media, values) instead of the
     * page; ← → go through the list, Esc closes, Ctrl/Cmd-click opens the
     * page as before. The title still opens the page.
     */
    public function addBrowsePeek(Event $event, string $resourceType): void
    {
        $view = $event->getTarget();
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        if (!$settings->get('batchvalues_browse_peek', true)
            || !$view->userIsAllowed(Controller\Admin\IndexController::class, 'peek')
        ) {
            return;
        }
        $view->headLink()->appendStylesheet($view->assetUrl('css/batch-values.css', 'BatchValues'));
        $view->headScript()->appendFile($view->assetUrl('js/batch-values-browse-peek.js', 'BatchValues'), 'text/javascript', ['defer' => 'defer']);
        echo $view->plugin('batchValuesRender')->peekAssets();
        echo '<div id="batchvalues-browse-peek" hidden'
            . ' data-url="' . $view->escapeHtmlAttr($view->url('admin/batch-values', ['action' => 'peek', 'id' => 987654321], ['query' => ['resource_type' => $resourceType]])) . '"'
            . ' data-title="' . $view->escapeHtmlAttr($view->translate('Quick look (Ctrl or Cmd-click: open the page)')) . '"'
            . '></div>';
    }

    public function addShowPeek(Event $event): void
    {
        $view = $event->getTarget();
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        if (!$settings->get('batchvalues_browse_peek', true)
            || !$view->userIsAllowed(Controller\Admin\IndexController::class, 'peek')
        ) {
            return;
        }
        $view->headLink()->appendStylesheet($view->assetUrl('css/batch-values.css', 'BatchValues'));
        echo $view->plugin('batchValuesRender')->peekAssets(true);
    }

    /**
     * Resource form: "Already in this field" next to a value that is there
     * already (0.6.2). Only marks, never changes or blocks anything.
     */
    public function addFormDuplicateMarks(Event $event): void
    {
        $view = $event->getTarget();
        $translate = $view->plugin('translate');
        $view->headLink()->appendStylesheet($view->assetUrl('css/batch-values.css', 'BatchValues'));
        $view->headScript()->appendFile($view->assetUrl('js/batch-values-form.js', 'BatchValues'), 'text/javascript', ['defer' => 'defer']);
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $confirm = (string) $settings->get('batchvalues_form_confirm', 'both');
        // What this server accepts in one POST: a long form silently loses
        // fields above these limits, and the save then fails (0.9.0).
        $maxInputVars = (int) ini_get('max_input_vars');
        $postMax = $this->bytesOfIni((string) ini_get('post_max_size'));
        echo '<div id="batchvalues-form-dup" hidden'
            . ' data-cancel-url="' . $view->escapeHtmlAttr((string) $this->cancelUrl($view)) . '"'
            . ' data-label="' . $view->escapeHtmlAttr($translate('Already in this field (value %d)')) . '"'
            . ' data-title="' . $view->escapeHtmlAttr($translate('The same value is already in this field, at the position shown. Remove one of them if it was added by mistake.')) . '"'
            . ' data-confirm="' . $view->escapeHtmlAttr(in_array($confirm, ['off', 'cancel', 'both'], true) ? $confirm : 'both') . '"'
            . ' data-confirm-save-title="' . $view->escapeHtmlAttr($translate('Save this?')) . '"'
            . ' data-confirm-save-text="' . $view->escapeHtmlAttr($translate('The changes below are written to the archive.')) . '"'
            . ' data-confirm-save-ok="' . $view->escapeHtmlAttr($translate('Yes, save')) . '"'
            . ' data-confirm-cancel-title="' . $view->escapeHtmlAttr($translate('Leave without saving?')) . '"'
            . ' data-confirm-cancel-text="' . $view->escapeHtmlAttr($translate('What was typed or picked on this page is lost. Nothing is written.')) . '"'
            . ' data-confirm-cancel-ok="' . $view->escapeHtmlAttr($translate('Yes, leave')) . '"'
            . ' data-confirm-back="' . $view->escapeHtmlAttr($translate('Back to the form')) . '"'
            . ' data-changes-none="' . $view->escapeHtmlAttr($translate('Nothing was changed on this page.')) . '"'
            . ' data-changes-some="' . $view->escapeHtmlAttr($translate('%d field(s) on this page were touched.')) . '"'
            . ' data-max-input-vars="' . (int) $maxInputVars . '"'
            . ' data-post-max="' . (int) $postMax . '"'
            . ' data-too-long-title="' . $view->escapeHtmlAttr($translate('This form is longer than the server accepts')) . '"'
            . ' data-too-long-text="' . $view->escapeHtmlAttr($translate('The server reads at most %1$d fields in one save; this form has %2$d. Saving now would drop the fields above that number and the save would fail, or part of the work would be lost. Save in smaller steps (fewer values at a time), or ask the administrator to raise max_input_vars in PHP.')) . '"'
            . ' data-too-big-text="' . $view->escapeHtmlAttr($translate('The server accepts at most %1$s in one save; this form is about %2$s. Save in smaller steps, or ask the administrator to raise post_max_size in PHP.')) . '"'
            . ' data-too-long-ok="' . $view->escapeHtmlAttr($translate('Save anyway')) . '"'
            . '></div>';
        $this->addFormValueTools($view);
    }

    /**
     * Sort a field, and move or copy values to another field, on Omeka's own
     * add and edit form (0.10.0).
     *
     * People were opening Batch Values on a single item only to run "Sort the
     * values" or "Move to another property". Both are rearrangements of the
     * form: Omeka reads the field of a value from the block it sits in, and
     * the order of the values from their order in that block, so nothing is
     * written until the person presses Save.
     */
    protected function addFormValueTools($view): void
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        if (!$settings->get('batchvalues_form_tools', true)) {
            return;
        }
        $translate = $view->plugin('translate');
        $view->headScript()->appendFile($view->assetUrl('js/batch-values-form-tools.js', 'BatchValues'), 'text/javascript', ['defer' => 'defer']);
        echo '<div id="batchvalues-form-tools" hidden'
            . ' data-sort-url="' . $view->escapeHtmlAttr($view->url('admin/batch-values', ['action' => 'sort-order'])) . '"'
            . ' data-asc="' . $view->escapeHtmlAttr($translate('A → Z')) . '"'
            . ' data-desc="' . $view->escapeHtmlAttr($translate('Z → A')) . '"'
            . ' data-asc-title="' . $view->escapeHtmlAttr($translate('Put the values of this field in order, by what is shown: the text, the label of a term, the title of a linked item. Nothing is written until this form is saved.')) . '"'
            . ' data-desc-title="' . $view->escapeHtmlAttr($translate('The same, the other way round. Nothing is written until this form is saved.')) . '"'
            . ' data-sorted="' . $view->escapeHtmlAttr($translate('%d value(s) put in order. Save to keep it.')) . '"'
            . ' data-move-field="' . $view->escapeHtmlAttr($translate('Move or copy…')) . '"'
            . ' data-move-field-title="' . $view->escapeHtmlAttr($translate('Take every value of this field to another field, or leave a copy there.')) . '"'
            . ' data-move-value="' . $view->escapeHtmlAttr($translate('Move or copy to another field…')) . '"'
            . ' data-move-value-title="' . $view->escapeHtmlAttr($translate('Take this one value to another field, or leave a copy there.')) . '"'
            . ' data-move-title="' . $view->escapeHtmlAttr($translate('Move or copy to another field')) . '"'
            . ' data-move-one-text="' . $view->escapeHtmlAttr($translate('One value of “%3$s”. Nothing is written until this form is saved.')) . '"'
            . ' data-move-many-text="' . $view->escapeHtmlAttr($translate('%1$d of the %2$d value(s) of “%3$s”. Nothing is written until this form is saved.')) . '"'
            . ' data-pick-all="' . $view->escapeHtmlAttr($translate('All')) . '"'
            . ' data-pick-none="' . $view->escapeHtmlAttr($translate('None')) . '"'
            . ' data-pick-links="' . $view->escapeHtmlAttr($translate('Only the links')) . '"'
            . ' data-pick-text="' . $view->escapeHtmlAttr($translate('Only the text')) . '"'
            . ' data-pick-uri="' . $view->escapeHtmlAttr($translate('Only the URIs')) . '"'
            . ' data-kind-link="' . $view->escapeHtmlAttr($translate('link')) . '"'
            . ' data-kind-text="' . $view->escapeHtmlAttr($translate('text')) . '"'
            . ' data-kind-uri="' . $view->escapeHtmlAttr($translate('URI')) . '"'
            . ' data-empty="' . $view->escapeHtmlAttr($translate('(empty)')) . '"'
            . ' data-done-title="' . $view->escapeHtmlAttr($translate('Done on this page, not written yet:')) . '"'
            . ' data-done-sort="' . $view->escapeHtmlAttr($translate('“%1$s” put in order, %2$s')) . '"'
            . ' data-done-move="' . $view->escapeHtmlAttr($translate('%1$d value(s) moved from “%2$s” to “%3$s”')) . '"'
            . ' data-done-copy="' . $view->escapeHtmlAttr($translate('%1$d value(s) copied from “%2$s” to “%3$s”')) . '"'
            . ' data-field="' . $view->escapeHtmlAttr($translate('Field')) . '"'
            . ' data-choose="' . $view->escapeHtmlAttr($translate('Choose a field…')) . '"'
            . ' data-group-often="' . $view->escapeHtmlAttr($translate('Where you usually move values')) . '"'
            . ' data-group-on-page="' . $view->escapeHtmlAttr($translate('Fields on this page')) . '"'
            . ' data-no-results="' . $view->escapeHtmlAttr($translate('No field of that name')) . '"'
            . ' data-show-all="' . $view->escapeHtmlAttr($translate('Also offer every other field of the archive')) . '"'
            . ' data-move="' . $view->escapeHtmlAttr($translate('Move (leave nothing here)')) . '"'
            . ' data-copy="' . $view->escapeHtmlAttr($translate('Copy (keep it here too)')) . '"'
            . ' data-move-ok="' . $view->escapeHtmlAttr($translate('Do it')) . '"'
            . ' data-back="' . $view->escapeHtmlAttr($translate('Back to the form')) . '"'
            . ' data-moved="' . $view->escapeHtmlAttr($translate('%1$d value(s) moved here from “%2$s”. Save to keep it.')) . '"'
            . ' data-copied="' . $view->escapeHtmlAttr($translate('%1$d value(s) copied here from “%2$s”. Save to keep it.')) . '"'
            . ' data-type-warning="' . $view->escapeHtmlAttr($translate('The template of “%2$s” says that field holds %3$s, and this is %1$s. It is still moved and still saved; only the buttons for adding a value there are different.')) . '"'
            . '></div>';
    }

    /**
     * Remove values by id and/or restore snapshotted values.
     *
     * Only acts on requests made by this module (request option
     * "batchvalues"), never on requests coming from the REST API or forms.
     */
    public function handleHydratePre(Event $event)
    {
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        if (!$request->getOption('batchvalues')) {
            return;
        }
        /** @var \Omeka\Entity\Resource $entity */
        $entity = $event->getParam('entity');
        $content = $request->getContent();
        $values = $entity->getValues();

        $em = $this->getServiceLocator()->get('Omeka\EntityManager');

        $removeIds = array_map('intval', $content['batchvalues_remove'] ?? []);
        $modify = [];
        foreach ($content['batchvalues_modify'] ?? [] as $item) {
            $modify[(int) $item['value_id']] = $item['after'];
        }
        if ($removeIds || $modify) {
            foreach ($values->toArray() as $key => $value) {
                $id = (int) $value->getId();
                if (in_array($id, $removeIds, true)) {
                    $values->remove($key);
                } elseif (isset($modify[$id])) {
                    // In place: same id, same position, same annotation.
                    $this->fillValue($value, $modify[$id], $em);
                }
            }
        }

        foreach ($content['batchvalues_restore'] ?? [] as $snapshot) {
            $value = new \Omeka\Entity\Value;
            $value->setResource($entity);
            $this->fillValue($value, $snapshot, $em);
            if (!empty($snapshot['annotation']) && is_array($snapshot['annotation'])) {
                $annotation = new \Omeka\Entity\ValueAnnotation;
                $annotation->setCreated(new \DateTime('now'));
                $annotation->setIsPublic(true);
                foreach ($snapshot['annotation'] as $annotationSnapshot) {
                    $annotationValue = new \Omeka\Entity\Value;
                    $annotationValue->setResource($annotation);
                    $this->fillValue($annotationValue, $annotationSnapshot, $em);
                    $annotation->getValues()->add($annotationValue);
                }
                $value->setValueAnnotation($annotation);
            }
            $values->add($value);
        }

        // Fields of media the API does not update (source, media type) or
        // that are set here for several media at once.
        if (!empty($content['batchvalues_media_fields']) && is_array($content['batchvalues_media_fields'])) {
            $medias = $entity instanceof \Omeka\Entity\Media ? [$entity] : ($entity instanceof \Omeka\Entity\Item ? $entity->getMedia()->toArray() : []);
            foreach ($medias as $media) {
                foreach ($content['batchvalues_media_fields'][(int) $media->getId()] ?? [] as $name => $fieldValue) {
                    $fieldValue = $fieldValue === '' ? null : $fieldValue;
                    switch ($name) {
                        case 'alt_text':
                            if (method_exists($media, 'setAltText')) {
                                $media->setAltText($fieldValue);
                            }
                            break;
                        case 'source':
                            $media->setSource($fieldValue);
                            break;
                        case 'media_type':
                            if ($fieldValue !== null) {
                                $media->setMediaType($fieldValue);
                            }
                            break;
                        case 'lang':
                            $media->setLang($fieldValue);
                            break;
                        case 'html':
                            // HTML media only; the text was cleaned by
                            // Omeka's HTML purifier when it was planned.
                            if ($media->getIngester() === 'html') {
                                $data = $media->getData();
                                $data = is_array($data) ? $data : [];
                                $data['html'] = (string) $fieldValue;
                                $media->setData($data);
                            }
                            break;
                    }
                }
            }
        }

        // Keep these keys away from anything else that reads the content.
        unset($content['batchvalues_remove'], $content['batchvalues_modify'], $content['batchvalues_restore'], $content['batchvalues_media_fields']);
        $request->setContent($content);
    }

    /**
     * Settings "When an item is saved": trim values and drop repeated ones in
     * the posted content, before Omeka reads it. Requests made by this module
     * are left alone (they carry exact snapshots).
     */
    public function cleanOnSave(Event $event)
    {
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        if ($request->getOption('batchvalues')) {
            return;
        }
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $trim = (bool) $settings->get('batchvalues_save_trim', false);
        $dedupe = (bool) $settings->get('batchvalues_save_dedupe', false);
        $linkType = (bool) $settings->get('batchvalues_save_link_type', true);
        if (!$trim && !$dedupe && !$linkType) {
            return;
        }
        $content = $request->getContent();
        $changed = false;
        // Omeka's own form offers one button for links, "Omeka resource",
        // which saves the general data type `resource`, unless the template
        // of the field says which kind of link it is. The same click then
        // gives `resource` in one field and `resource:item` in another, and
        // the two are not the same value for Omeka. Here every link is saved
        // with its exact data type, as long as the template allows it.
        $linkTypes = $linkType ? $this->linkTypesOf($content, $request) : [];
        $linkFix = ['converted' => [], 'dropped' => []];
        foreach ($content as $key => $values) {
            // Property terms ("dcterms:title") hold lists of value objects.
            if (!is_string($key) || strpos($key, ':') === false || strpos($key, 'o:') === 0 || !is_array($values) || array_values($values) !== $values) {
                continue;
            }
            $seen = [];
            $links = [];
            $kept = [];
            foreach ($values as $value) {
                if (!is_array($value)) {
                    $kept[] = $value;
                    continue;
                }
                $madeExact = false;
                if ($linkType && ($value['type'] ?? null) === 'resource' && !empty($value['value_resource_id'])) {
                    $exact = $linkTypes[(int) $value['value_resource_id']] ?? null;
                    if ($exact !== null && $this->typeAllowed($exact, $key, $linkTypes['@template'] ?? [])) {
                        $value['type'] = $exact;
                        $madeExact = true;
                        $changed = true;
                        $linkFix['converted'][$key][$exact] = ($linkFix['converted'][$key][$exact] ?? 0) + 1;
                    }
                }
                if ($linkType && !empty($value['value_resource_id']) && empty($value['@annotation'])) {
                    // A link that this setting has just made exact and that is
                    // now the same as another link of the field is not saved
                    // twice: the field would hold the same item twice.
                    $signature = json_encode([
                        $value['type'] ?? null, (int) $value['value_resource_id'],
                        $value['@language'] ?? ($value['o:lang'] ?? null),
                        !isset($value['is_public']) || !empty($value['is_public']),
                    ]);
                    if ($madeExact && isset($links[$signature])) {
                        $changed = true;
                        $linkFix['dropped'][$key] = ($linkFix['dropped'][$key] ?? 0) + 1;
                        continue;
                    }
                    $links[$signature] = true;
                }
                if ($trim) {
                    foreach (['@value', 'o:label', '@id'] as $k) {
                        if (isset($value[$k]) && is_string($value[$k])) {
                            $new = preg_replace('/^[\s\x{00A0}\x{200B}\x{FEFF}]+|[\s\x{00A0}\x{200B}\x{FEFF}]+$/u', '', $value[$k]);
                            if ($new !== null && $new !== $value[$k]) {
                                $value[$k] = $new;
                                $changed = true;
                            }
                        }
                    }
                }
                if ($dedupe && empty($value['@annotation'])) {
                    $signature = json_encode([
                        $value['type'] ?? null, $value['@value'] ?? null, $value['@id'] ?? null, $value['o:label'] ?? null,
                        isset($value['value_resource_id']) ? (int) $value['value_resource_id'] : null,
                        $value['@language'] ?? ($value['o:lang'] ?? null),
                        !isset($value['is_public']) || !empty($value['is_public']),
                    ]);
                    if (isset($seen[$signature])) {
                        $changed = true;
                        continue;
                    }
                    $seen[$signature] = true;
                }
                $kept[] = $value;
            }
            $content[$key] = $kept;
        }
        if ($changed) {
            $request->setContent($content);
        }
        if ($linkFix['converted'] || $linkFix['dropped']) {
            // This save is the person's; only the data type of some links was
            // set by this module. The option says so, field by field, so the
            // archive's history can name what did it (0.9.0). The general
            // option "batchvalues" is not set here: it means "this whole
            // request comes from Batch Values", which would be wrong.
            $request->setOption('batchvalues_link_type', array_filter($linkFix));
        }
    }

    /**
     * "8M", "512K", "1G" of php.ini as bytes; 0 when there is no limit.
     */
    /**
     * The fields the buttons on Omeka's own form touched, before the save
     * (0.11.0).
     *
     * The form sends the list; nothing else is trusted from it. The state of
     * those fields is read here, while the database still holds it, so that
     * the log can show both sides. Value ids are not carried across: Omeka
     * reuses the value rows of the whole resource in order when it saves
     * (ValueHydrator), so the two sides are compared place by place, as the
     * "Sort the values" operation already is.
     */
    public function logFormEditPre(Event $event): void
    {
        $request = $event->getParam('request');
        if ($request->getOption('batchvalues')) {
            return;
        }
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        if (!$settings->get('batchvalues_form_tools', true) || !$settings->get('batchvalues_form_log', true)) {
            return;
        }
        $posted = $this->formActionsPosted();
        if (!$posted) {
            return;
        }
        $id = (int) $request->getId();
        if (!$id || !$this->isTheResourceBeingEdited($request->getResource(), $id)) {
            return;
        }
        $properties = $this->propertyIdsOf($posted['fields']);
        if (!$properties) {
            return;
        }
        $this->formEdit[$request->getResource() . '#' . $id] = [
            'actions' => $posted['actions'],
            'properties' => $properties,
            'before' => $this->valueRowsOf($id, $properties),
        ];
    }

    /**
     * The same fields after the save, written to the log as one run.
     */
    public function logFormEditPost(Event $event): void
    {
        $request = $event->getParam('request');
        $id = (int) $request->getId();
        $key = $request->getResource() . '#' . $id;
        if (empty($this->formEdit[$key])) {
            return;
        }
        $kept = $this->formEdit[$key];
        unset($this->formEdit[$key]);
        try {
            $services = $this->getServiceLocator();
            $after = $this->valueRowsOf($id, $kept['properties']);
            $before = $kept['before'];
            $fields = [];
            $pairs = [];
            $touched = false;
            foreach ($kept['properties'] as $propertyId) {
                $was = array_values(array_filter($before, function ($row) use ($propertyId) {
                    return (int) $row['property_id'] === $propertyId;
                }));
                $now = array_values(array_filter($after, function ($row) use ($propertyId) {
                    return (int) $row['property_id'] === $propertyId;
                }));
                $fields[$propertyId] = [
                    'before' => array_map('intval', array_column($was, 'id')),
                    'after' => array_map('intval', array_column($now, 'id')),
                ];
                foreach ($was as $row) {
                    $pairs[] = ['value_id' => (int) $row['id'], 'before' => Planner::snapshotFromRow($row), 'after' => null, 'reason' => 'sort'];
                }
                foreach ($now as $row) {
                    $snapshot = Planner::snapshotFromRow($row);
                    unset($snapshot['value_id']);
                    $pairs[] = ['value_id' => null, 'before' => null, 'after' => $snapshot, 'reason' => 'sort'];
                }
                if ($this->rowsDiffer($was, $now)) {
                    $touched = true;
                }
            }
            if (!$touched) {
                return;
            }
            $resourceType = isset(Planner::RESOURCE_TYPES[$request->getResource()]) ? $request->getResource() : 'items';
            $log = $services->get(ChangeLog::class);
            $first = (int) array_key_first($fields);
            $property = $services->get('Omeka\ApiManager')->read('properties', $first)->getContent();
            $setId = $log->createSet([
                'operation' => self::FORM_EDIT,
                'resource_type' => $resourceType,
                'property_id' => count($fields) === 1 ? $first : null,
                'property_term' => count($fields) === 1 ? $property->term() : null,
                'property_label' => count($fields) === 1 ? $property->label() : null,
                'params' => ['actions' => $kept['actions'], 'display' => $this->formEditDisplay($kept['actions'])],
                'selection' => ['ids' => [$id]],
                'total' => 1,
            ]);
            $title = null;
            try {
                $title = $services->get('Omeka\ApiManager')->read($request->getResource(), $id)->getContent()->displayTitle();
            } catch (\Throwable $e) {
            }
            $changeId = $log->addChange($setId, [
                'resource_id' => $id,
                'resource_title' => $title,
                'outcome' => 'changed',
                'values' => ['pairs' => $pairs],
            ]);
            $log->setFieldData($changeId, $fields);
            $log->bump($setId, 1, 1, 0, 0);
            $log->finishSet($setId, 'done');
        } catch (\Throwable $e) {
            // The log must never stop a save made by a person.
            $this->getServiceLocator()->get('Omeka\Logger')->err(
                sprintf('Batch Values could not log a form edit of %s %d: %s', $request->getResource(), $id, $e->getMessage())
            );
        }
    }

    /**
     * What the buttons reported doing, read from the POST of Omeka's form.
     * Only the field terms and the wording are used; the state itself is
     * always read from the database.
     */
    protected function formActionsPosted(): ?array
    {
        $mvc = $this->getServiceLocator()->get('Application')->getMvcEvent();
        $http = $mvc ? $mvc->getRequest() : null;
        if (!$http || !method_exists($http, 'isPost') || !$http->isPost()) {
            return null;
        }
        $raw = $http->getPost('batchvalues_form_actions');
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['fields']) || !is_array($data['fields'])) {
            return null;
        }
        $fields = [];
        foreach ($data['fields'] as $term) {
            if (is_string($term) && preg_match('/^[a-zA-Z0-9_-]+:[a-zA-Z0-9_-]+$/', $term)) {
                $fields[] = $term;
            }
        }
        if (!$fields) {
            return null;
        }
        $actions = [];
        foreach ((array) ($data['actions'] ?? []) as $action) {
            if (is_array($action) && isset($action['kind'])) {
                $actions[] = [
                    'kind' => (string) $action['kind'],
                    'n' => (int) ($action['n'] ?? 0),
                    'field' => (string) ($action['field'] ?? ''),
                    'target' => (string) ($action['target'] ?? ''),
                    'direction' => (string) ($action['direction'] ?? ''),
                ];
            }
        }
        return ['fields' => array_values(array_unique($fields)), 'actions' => $actions];
    }

    /**
     * The one resource whose form was sent, and no other.
     *
     * A save can carry other resources with it (media of an item, for one).
     * Only the resource the page is editing is logged, so a save never leaves
     * entries for things the person was not looking at.
     */
    protected function isTheResourceBeingEdited(string $resource, int $id): bool
    {
        $mvc = $this->getServiceLocator()->get('Application')->getMvcEvent();
        $route = $mvc ? $mvc->getRouteMatch() : null;
        if (!$route) {
            return false;
        }
        // Before the page is dispatched the route holds the short controller
        // name ("item-set"); after it, the class, with the short name moved to
        // __CONTROLLER__. Both are looked at, and case is ignored.
        $map = [
            'item' => 'items',
            'item-set' => 'item_sets',
            'media' => 'media',
            'omeka\controller\admin\item' => 'items',
            'omeka\controller\admin\itemset' => 'item_sets',
            'omeka\controller\admin\media' => 'media',
        ];
        $found = null;
        foreach ([$route->getParam('__CONTROLLER__'), $route->getParam('controller')] as $candidate) {
            if (is_string($candidate) && isset($map[strtolower($candidate)])) {
                $found = $map[strtolower($candidate)];
                break;
            }
        }
        return $found === $resource
            && (int) $route->getParam('id') === $id
            && in_array((string) $route->getParam('action'), ['edit', 'add'], true);
    }

    protected function propertyIdsOf(array $terms): array
    {
        $ids = [];
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        foreach ($terms as $term) {
            [$prefix, $local] = explode(':', $term, 2);
            $id = (int) $connection->fetchOne(
                'SELECT p.id FROM property p JOIN vocabulary v ON v.id = p.vocabulary_id WHERE v.prefix = ? AND p.local_name = ?',
                [$prefix, $local]
            );
            if ($id) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    protected function valueRowsOf(int $resourceId, array $propertyIds): array
    {
        if (!$propertyIds) {
            return [];
        }
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        return $connection->fetchAllAssociative(
            'SELECT id, property_id, type, `value`, uri, value_resource_id, lang, is_public FROM `value`'
            . ' WHERE resource_id = ? AND property_id IN (' . implode(',', array_map('intval', $propertyIds)) . ') ORDER BY id',
            [$resourceId]
        );
    }

    protected function rowsDiffer(array $was, array $now): bool
    {
        $key = function (array $row) {
            return implode('|', [
                $row['type'], (string) $row['value'], (string) $row['uri'],
                (string) $row['value_resource_id'], (string) $row['lang'], (int) (bool) $row['is_public'],
            ]);
        };
        return array_map($key, $was) !== array_map($key, $now);
    }

    protected function formEditDisplay(array $actions): array
    {
        $display = [];
        foreach ($actions as $action) {
            if (($action['kind'] ?? '') === 'sort') {
                $display[] = ['label' => 'Put in order', 'text' => sprintf('%s (%s)', $action['field'], ($action['direction'] ?? 'asc') === 'desc' ? 'Z to A' : 'A to Z')]; // @translate
            } elseif (($action['kind'] ?? '') === 'copy') {
                $display[] = ['label' => 'Copied', 'text' => sprintf('%d from %s to %s', $action['n'], $action['field'], $action['target'])]; // @translate
            } elseif (($action['kind'] ?? '') === 'move') {
                $display[] = ['label' => 'Moved', 'text' => sprintf('%d from %s to %s', $action['n'], $action['field'], $action['target'])]; // @translate
            }
        }
        return $display;
    }

    protected function bytesOfIni(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1' || $value === '0') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        switch ($unit) {
            case 'g':
                return (int) ($number * 1024 * 1024 * 1024);
            case 'm':
                return (int) ($number * 1024 * 1024);
            case 'k':
                return (int) ($number * 1024);
            default:
                return (int) $number;
        }
    }

    /**
     * The exact data type of every resource linked in the posted content
     * ([resource id => resource:item|resource:itemset|resource:media]), plus
     * the data types the template sets for each field, under '@template'.
     */
    protected function linkTypesOf(array $content, $request): array
    {
        $ids = [];
        foreach ($content as $key => $values) {
            if (!is_string($key) || strpos($key, ':') === false || strpos($key, 'o:') === 0 || !is_array($values)) {
                continue;
            }
            foreach ($values as $value) {
                if (is_array($value) && ($value['type'] ?? null) === 'resource' && !empty($value['value_resource_id'])) {
                    $ids[(int) $value['value_resource_id']] = true;
                }
            }
        }
        if (!$ids) {
            return [];
        }
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $classes = [
            'Omeka\Entity\Item' => 'resource:item',
            'Omeka\Entity\ItemSet' => 'resource:itemset',
            'Omeka\Entity\Media' => 'resource:media',
        ];
        $types = [];
        $rows = $connection->fetchAllAssociative(
            'SELECT id, resource_type FROM resource WHERE id IN (?)',
            [array_keys($ids)],
            [\Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
        );
        foreach ($rows as $row) {
            if (isset($classes[$row['resource_type']])) {
                $types[(int) $row['id']] = $classes[$row['resource_type']];
            }
        }
        if (!$types) {
            return [];
        }
        // The template of the resource being saved, to leave a field alone
        // when its template asks for another data type.
        $templateId = isset($content['o:resource_template']['o:id'])
            ? (int) $content['o:resource_template']['o:id']
            : null;
        if ($templateId === null && $request->getId()) {
            $templateId = (int) $connection->fetchOne('SELECT resource_template_id FROM resource WHERE id = ?', [$request->getId()]);
        }
        $template = [];
        if ($templateId) {
            $rows = $connection->fetchAllAssociative(
                "SELECT CONCAT(v.prefix, ':', p.local_name) AS term, rtp.data_type"
                . ' FROM resource_template_property rtp JOIN property p ON p.id = rtp.property_id'
                . ' JOIN vocabulary v ON v.id = p.vocabulary_id WHERE rtp.resource_template_id = ?',
                [$templateId]
            );
            foreach ($rows as $row) {
                $types_ = $row['data_type'] ? json_decode($row['data_type'], true) : null;
                $template[$row['term']] = is_array($types_) ? $types_ : [];
            }
        }
        $types['@template'] = $template;
        return $types;
    }

    /**
     * Whether the template lets this field hold this data type. A field the
     * template says nothing about takes it.
     */
    protected function typeAllowed(string $type, string $term, array $template): bool
    {
        $allowed = $template[$term] ?? [];
        return !$allowed || in_array($type, $allowed, true);
    }

    /**
     * Set the columns of a value from a snapshot.
     */
    protected function fillValue(\Omeka\Entity\Value $value, array $snapshot, $em): void
    {
        if (!empty($snapshot['property_id'])) {
            $value->setProperty($em->getReference(\Omeka\Entity\Property::class, (int) $snapshot['property_id']));
        }
        $value->setType((string) $snapshot['type']);
        $value->setValue($snapshot['value'] ?? null);
        $value->setUri($snapshot['uri'] ?? null);
        $value->setLang(isset($snapshot['lang']) && $snapshot['lang'] !== '' ? $snapshot['lang'] : null);
        $value->setIsPublic(!array_key_exists('is_public', $snapshot) || !empty($snapshot['is_public']));
        $value->setValueResource(empty($snapshot['value_resource_id'])
            ? null
            : $em->getReference(\Omeka\Entity\Resource::class, (int) $snapshot['value_resource_id']));
    }

    public function addBrowseBatchActions(Event $event, string $resourceType = 'items')
    {
        $view = $event->getTarget();
        if (!$view->userIsAllowed(Controller\Admin\IndexController::class, 'new')) {
            return;
        }
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $view->headScript()->appendFile($view->assetUrl('js/batch-values-browse.js', 'BatchValues'));
        $url = $view->url('admin/batch-values', ['action' => 'new'], ['query' => ['resource_type' => $resourceType]]);
        $translate = $view->plugin('translate');
        echo '<div id="batchvalues-browse" hidden'
            . ' data-url="' . $view->escapeHtmlAttr($url) . '"'
            . ' data-hide-core="' . ($settings->get('batchvalues_hide_core_batch_edit') ? '1' : '0') . '"'
            . ' data-label-selected="' . $view->escapeHtmlAttr($translate('Edit with Batch Values (%d ticked)')) . '"'
            . ' data-label-all="' . $view->escapeHtmlAttr($translate('Edit with Batch Values (all results of this search)')) . '"'
            . '></div>';
    }
}

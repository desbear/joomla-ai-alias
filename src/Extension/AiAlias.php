<?php

/**
 * @package     AI Alias
 * @subpackage  plg_system_aialias
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\System\AiAlias\Extension;

use Gss\Plugin\System\AiAlias\Service\AliasGenerator;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Adds an "AI alias" button to the article editor and fills empty aliases of saved articles.
 */
final class AiAlias extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    private const CONTEXTS = ['com_content.article', 'com_content.form'];

    /** Automatic generation is off. */
    private const AUTO_OFF = 0;

    /** Only articles saved without the edit form (API, MCP, importers, CLI). */
    private const AUTO_NONFORM = 1;

    /** Also articles saved from the edit form without a usable alias (the default). */
    private const AUTO_ALL = 2;

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterDispatch'      => 'onAfterDispatch',
            'onContentBeforeSave'  => 'onContentBeforeSave',
            'onExtensionBeforeSave' => 'onExtensionBeforeSave',
            'onAjaxAialias'        => 'onAjaxAialias',
        ];
    }

    /**
     * Load the button script on the article and FLEXIcontent item edit screens.
     */
    public function onAfterDispatch(): void
    {
        $app = $this->getApplication();

        if ((!$app->isClient('administrator') && !$app->isClient('site')) || !$this->isEditForm()) {
            return;
        }

        $doc = $app->getDocument();

        if (!$doc || $doc->getType() !== 'html') {
            return;
        }

        $doc->addScriptOptions('plg_system_aialias', [
            'url'        => 'index.php?option=com_ajax&plugin=aialias&group=system&format=json',
            'contentIds' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) $this->params->get('content_ids', 'jform_articletext,jform_text,jform_description'))
            ))),
        ]);

        foreach (['BUTTON', 'WORKING', 'CONFIRM_OVERWRITE', 'NEED_TITLE', 'DONE_AI', 'DONE_FALLBACK', 'ERROR'] as $key) {
            Text::script('PLG_SYSTEM_AIALIAS_JS_' . $key);
        }

        $doc->getWebAssetManager()->registerAndUseScript(
            'plg_system_aialias.button',
            'plg_system_aialias/aialias.js',
            [],
            ['defer' => true],
            ['core']
        );
    }

    /**
     * Fill the alias of articles that arrive without one.
     */
    public function onContentBeforeSave(EventInterface $event): void
    {
        $mode = (int) $this->params->get('auto_mode', self::AUTO_ALL);

        // Joomla 5 passes a BeforeSaveEvent, Joomla 4 and FLEXIcontent a plain event with positional arguments
        $context = (string) $this->arg($event, 'context', 0, '');

        if ($mode === self::AUTO_OFF || !\in_array($context, self::CONTEXTS, true)) {
            return;
        }

        $table = $this->arg($event, 'subject', 1);
        $isNew = (bool) $this->arg($event, 'isNew', 2, false);
        $data  = (array) $this->arg($event, 'data', 3, []);

        if (!\is_object($table)) {
            return;
        }
        $jform = $this->getApplication()->getInput()->post->get('jform', null, 'array');

        // FLEXIcontent fires the same event with the com_content context, so tell them apart by the item class
        $flexi = stripos(\get_class($table), 'flexicontent') !== false || isset($table->type_id);

        if (!$this->params->get($flexi ? 'com_flexicontent' : 'com_content', 1)) {
            return;
        }

        if (\is_array($jform) && \array_key_exists('title', $jform)) {
            // A person pressed Save in the edit form. The model has already filled $data['alias'] from
            // the title by now, so look at what was actually submitted.
            if ($mode !== self::AUTO_ALL || !$this->needsAlias((string) ($jform['alias'] ?? ''))) {
                return;
            }
        } elseif (!$isNew || !$this->needsAlias((string) ($data['alias'] ?? ''))) {
            // Updates without an alias keep the stored one; supplied aliases are respected
            return;
        }

        $text = (string) ($table->introtext ?? '') . ' ' . (string) ($table->fulltext ?? '');

        try {
            $result = $this->generator()->generate((string) $table->title, $text, (string) ($table->language ?? '*'));
        } catch (\Throwable $e) {
            // Fallback disabled for this kind of error: leave the alias Joomla generated in Table::check()
            return;
        }

        $table->alias = $this->generator()->makeUnique($result['alias'], (int) $table->catid, (int) $table->id);
    }

    /**
     * The API key field is locked, so the browser does not submit it unless the editor pressed
     * Modify. Put the stored key back, otherwise saving the settings would wipe it.
     */
    public function onExtensionBeforeSave(EventInterface $event): void
    {
        $context = (string) $this->arg($event, 'context', 0, '');
        $table   = $this->arg($event, 'subject', 1);

        if (
            $context !== 'com_plugins.plugin' || !\is_object($table)
            || ($table->element ?? '') !== 'aialias' || ($table->folder ?? '') !== 'system'
        ) {
            return;
        }

        $params = $table->params;
        $params = \is_string($params) ? json_decode($params, true) : (array) $params;

        if (!\is_array($params) || \array_key_exists('api_key', $params)) {
            // Present means the field was unlocked: keep what was typed, even an empty value
            return;
        }

        $params['api_key'] = (string) $this->params->get('api_key', '');
        $table->params     = json_encode($params);
    }

    /**
     * com_ajax endpoint for the editor button.
     */
    public function onAjaxAialias(EventInterface $event): void
    {
        $app   = $this->getApplication();
        $input = $app->getInput();
        $user  = $app->getIdentity();

        if (!Session::checkToken()) {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN_NOTICE'), 403);
        }

        if (
            !$user || $user->guest
            || !($user->authorise('core.create', 'com_content') || $user->authorise('core.edit', 'com_content') || $user->authorise('core.edit.own', 'com_content'))
        ) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $title = trim($input->post->getString('title', ''));

        if ($title === '') {
            throw new \InvalidArgumentException(Text::_('PLG_SYSTEM_AIALIAS_JS_NEED_TITLE'), 400);
        }

        $result = $this->generator()->generate(
            $title,
            $input->post->get('text', '', 'raw'),
            $input->post->getString('language', '*')
        );

        $result['alias'] = $this->generator()->makeUnique($result['alias'], $input->post->getInt('catid'), $input->post->getInt('id'));

        // Joomla 5's AjaxEvent locks its arguments, Joomla 4 hands us a plain event
        if (method_exists($event, 'addResult')) {
            $event->addResult($result);
        } else {
            $results   = (array) $event->getArgument('result', []);
            $results[] = $result;

            $event->setArgument('result', $results);
        }
    }

    /**
     * An alias is ours to generate when it is empty, or when it is not URL safe yet - a Chinese alias
     * would otherwise be transliterated into pinyin, which is what the AI is meant to replace.
     */
    private function needsAlias(string $alias): bool
    {
        $alias = trim($alias);

        return $alias === '' || preg_match('/^[\x21-\x7E]+$/', $alias) !== 1;
    }

    /**
     * Read an event argument by name, falling back to its position in legacy events.
     *
     * @param   mixed  $default  Returned when neither the name nor the position is set
     *
     * @return  mixed
     */
    private function arg(EventInterface $event, string $name, int $position, $default = null)
    {
        $value = $event->getArgument($name);

        return $value ?? $event->getArgument($position, $default);
    }

    /**
     * True on the article edit form of com_content or the item edit form of FLEXIcontent.
     */
    private function isEditForm(): bool
    {
        $app    = $this->getApplication();
        $input  = $app->getInput();
        $option = $input->getCmd('option');

        if ($option === 'com_content' && $this->params->get('com_content', 1)) {
            $view = $app->isClient('administrator') ? 'article' : 'form';

            return $input->getCmd('view') === $view && $input->getCmd('layout') === 'edit';
        }

        if ($option === 'com_flexicontent' && $this->params->get('com_flexicontent', 1)) {
            if ($input->getCmd('view') !== 'item') {
                return false;
            }

            // On the site the item view also shows items to visitors; the form sets a layout or a task
            if ($app->isClient('site')) {
                $task = preg_replace('/^.*\\./', '', strtolower($input->getCmd('task', '')));

                return $input->getCmd('layout') === 'form' || \in_array($task, ['add', 'edit'], true);
            }

            return true;
        }

        return false;
    }

    private function generator(): AliasGenerator
    {
        static $generator;

        return $generator ??= new AliasGenerator(
            $this->params,
            Factory::getContainer()->get(DatabaseInterface::class),
            $this->getApplication()
        );
    }
}

<?php

/**
 * @package     AI Alias
 * @subpackage  plg_system_aialias
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\System\AiAlias\Service;

use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Asks an OpenAI-compatible API for an SEO slug and falls back to Joomla's own conversion.
 */
final class AliasGenerator
{
    /** The API ran out of credits; skip it until the cooldown expires. */
    public const REASON_QUOTA = 'quota';

    /** Too many calls in this minute; use the PHP conversion instead of spending more credits. */
    public const REASON_RATE = 'rate';

    private const FLAG_FILE = 'plg_system_aialias_quota.flag';

    private const RATE_FILE = 'plg_system_aialias_rate.json';

    /** Hard stop for the uniqueness loop, so a broken query cannot spin forever. */
    private const MAX_UNIQUE_TRIES = 100;

    /** @var Registry */
    private $params;

    /** @var DatabaseInterface */
    private $db;

    /** @var CMSApplicationInterface */
    private $app;

    public function __construct(Registry $params, DatabaseInterface $db, CMSApplicationInterface $app)
    {
        $this->params = $params;
        $this->db     = $db;
        $this->app    = $app;
    }

    /**
     * @return  array{alias: string, source: string, reason: string}
     *          source is "ai" or "fallback"; reason explains why the fallback was used
     */
    public function generate(string $title, string $text = '', string $language = '*'): array
    {
        $reason = '';

        try {
            $alias = $this->fromAi($title, $text);

            if ($alias !== '') {
                return ['alias' => $alias, 'source' => 'ai', 'reason' => ''];
            }

            $reason = Text::_('PLG_SYSTEM_AIALIAS_ERR_EMPTY');
        } catch (AiException $e) {
            $reason = $e->getMessage();

            $soft = $e->reason === self::REASON_QUOTA || $e->reason === self::REASON_RATE;

            if (!$soft && !$this->params->get('fallback_on_error', 1)) {
                throw $e;
            }
        }

        $this->log('Fallback for "' . $title . '": ' . $reason, Log::WARNING);

        return ['alias' => $this->fallback($title, $language), 'source' => 'fallback', 'reason' => $reason];
    }

    /**
     * Joomla's own conversion, as the core would do it.
     */
    public function fallback(string $title, string $language = '*'): string
    {
        $alias = $this->params->get('fallback', 'joomla') === 'unicode'
            ? OutputFilter::stringUrlUnicodeSlug($title)
            : ApplicationHelper::stringURLSafe($title, $language);

        if (trim(str_replace('-', '', $alias)) === '') {
            $alias = Factory::getDate()->format('Y-m-d-H-i-s');
        }

        return $alias;
    }

    /**
     * Append -2, -3, ... until no other article in the category uses the alias.
     */
    public function makeUnique(string $alias, int $catid, int $id = 0): string
    {
        $base = $alias;

        for ($i = 2; $this->aliasExists($alias, $catid, $id); $i++) {
            if ($i > self::MAX_UNIQUE_TRIES) {
                return $base . '-' . Factory::getDate()->format('YmdHis');
            }

            $alias = $base . '-' . $i;
        }

        return $alias;
    }

    private function aliasExists(string $alias, int $catid, int $id): bool
    {
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__content'))
            ->where($this->db->quoteName('alias') . ' = :alias')
            ->where($this->db->quoteName('catid') . ' = :catid')
            ->where($this->db->quoteName('id') . ' != :id')
            ->bind(':alias', $alias)
            ->bind(':catid', $catid, ParameterType::INTEGER)
            ->bind(':id', $id, ParameterType::INTEGER);

        return (int) $this->db->setQuery($query)->loadResult() > 0;
    }

    private function fromAi(string $title, string $text): string
    {
        // Drop whitespace and invisible characters picked up when copying the key
        $key = preg_replace('/[^\x21-\x7E]/', '', (string) $this->params->get('api_key', ''));

        if ($key === '') {
            throw new AiException(Text::_('PLG_SYSTEM_AIALIAS_ERR_NO_KEY'));
        }

        if ($this->inQuotaCooldown()) {
            throw new AiException(Text::_('PLG_SYSTEM_AIALIAS_ERR_QUOTA_COOLDOWN'), self::REASON_QUOTA);
        }

        if (!$this->withinRateLimit()) {
            throw new AiException(Text::_('PLG_SYSTEM_AIALIAS_ERR_RATE'), self::REASON_RATE);
        }

        $max    = (int) $this->params->get('max_length', 60);
        $system = 'You create SEO-optimised URL slugs for articles. '
            . 'Always write the slug in English, even when the article is in Chinese or another language: '
            . 'translate the meaning, do not transliterate (no pinyin). '
            . 'Use lowercase ASCII letters, digits and hyphens only, 3 to 8 words, at most ' . $max . ' characters. '
            . 'Lead with the primary search keyword, keep product names and version numbers, drop stop words '
            . '(a, an, the, of, and, to, for, in, on) unless needed for meaning. '
            . 'Reply with JSON only: {"alias":"your-slug"}';

        $extra = trim((string) $this->params->get('extra_prompt', ''));

        if ($extra !== '') {
            $system .= "\n\nSite-specific guidance:\n" . $extra;
        }

        $user = 'Title: ' . $title;
        $text = $this->excerpt($text);

        if ($text !== '') {
            $user .= "\n\nArticle excerpt:\n" . $text;
        }

        $payload = [
            'model'           => (string) $this->params->get('model', 'gpt-4.1-mini'),
            'messages'        => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'response_format' => ['type' => 'json_object'],
        ];

        $url = rtrim((string) $this->params->get('api_base', 'https://api.openai.com/v1'), '/') . '/chat/completions';

        try {
            $response = HttpFactory::getHttp()->post(
                $url,
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'],
                (int) $this->params->get('timeout', 15)
            );
        } catch (\Throwable $e) {
            throw new AiException(Text::sprintf('PLG_SYSTEM_AIALIAS_ERR_REQUEST', $this->shorten($e->getMessage())));
        }

        $code = $this->statusCode($response);
        $raw  = $this->responseBody($response);
        $body = json_decode($raw, true);

        if ($code !== 200) {
            $error = \is_array($body['error'] ?? null) ? $body['error'] : [];
            $errorCode  = (string) ($error['code'] ?? $error['type'] ?? '');

            // 429 is also used for plain rate limits; only a billing problem trips the cooldown
            if ($errorCode === 'insufficient_quota' || $errorCode === 'billing_hard_limit_reached' || $code === 402) {
                $this->startQuotaCooldown();

                throw new AiException(Text::_('PLG_SYSTEM_AIALIAS_ERR_QUOTA'), self::REASON_QUOTA);
            }

            if ($code === 401) {
                throw new AiException(Text::sprintf('PLG_SYSTEM_AIALIAS_ERR_BAD_KEY', substr($key, -4)));
            }

            throw new AiException(Text::sprintf('PLG_SYSTEM_AIALIAS_ERR_API', $code, $this->shorten((string) ($error['message'] ?? $raw))));
        }

        $content = (string) ($body['choices'][0]['message']['content'] ?? '');
        $json    = json_decode($content, true);
        $alias   = \is_array($json) ? (string) ($json['alias'] ?? '') : $content;

        $alias = $this->clean($alias, $max);

        if ($alias !== '') {
            $this->log('AI alias for "' . $title . '": ' . $alias);
        }

        return $alias;
    }

    /**
     * Force the slug into lowercase ASCII and cut it at a hyphen within the length limit.
     */
    private function clean(string $alias, int $max): string
    {
        $alias = OutputFilter::stringURLSafe($alias);

        if (\strlen($alias) > $max) {
            $alias = substr($alias, 0, $max);
            $cut   = strrpos($alias, '-');

            if ($cut !== false && $cut > $max / 2) {
                $alias = substr($alias, 0, $cut);
            }
        }

        $alias = trim($alias, '-');

        // Joomla rejects purely numeric aliases or turns them into dates
        return trim(str_replace('-', '', $alias)) === '' || ctype_digit(str_replace('-', '', $alias)) ? '' : $alias;
    }

    /**
     * Joomla 4 and 5 expose code/body as properties; Joomla 6 drops them for the PSR-7 interface.
     *
     * @param  object  $response
     */
    private function statusCode($response): int
    {
        return method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : (int) $response->code;
    }

    /**
     * @param  object  $response
     */
    private function responseBody($response): string
    {
        if (!method_exists($response, 'getBody')) {
            return (string) $response->body;
        }

        $stream = $response->getBody();

        if (\is_object($stream) && method_exists($stream, 'isSeekable') && $stream->isSeekable()) {
            $stream->rewind();
        }

        return (string) $stream;
    }

    /**
     * Keep error details short enough for the message bar; hide echoed keys.
     */
    private function shorten(string $message): string
    {
        $message = trim(preg_replace('/sk-[A-Za-z0-9_*\-]+/', 'sk-…', strip_tags($message)));

        return mb_strlen($message) > 120 ? mb_substr($message, 0, 120) . '…' : $message;
    }

    private function excerpt(string $html): string
    {
        $text = html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html)), ENT_QUOTES, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, 1500);
    }

    private function flagPath(): string
    {
        return rtrim((string) $this->app->get('tmp_path', JPATH_ROOT . '/tmp'), '/\\') . '/' . self::FLAG_FILE;
    }

    /**
     * Count the calls of the current minute, so a logged-in author - or a runaway import - cannot
     * spend the whole API budget. Returns false once the configured limit is reached.
     */
    private function withinRateLimit(): bool
    {
        $limit = (int) $this->params->get('rate_limit', 20);

        if ($limit <= 0) {
            return true;
        }

        $file   = \dirname($this->flagPath()) . '/' . self::RATE_FILE;
        $minute = (int) floor(time() / 60);
        $state  = [];

        if (is_file($file)) {
            $state = json_decode((string) @file_get_contents($file), true) ?: [];
        }

        $count = (int) ($state['minute'] ?? -1) === $minute ? (int) ($state['count'] ?? 0) : 0;

        if ($count >= $limit) {
            return false;
        }

        @file_put_contents($file, json_encode(['minute' => $minute, 'count' => $count + 1]), LOCK_EX);

        return true;
    }

    private function inQuotaCooldown(): bool
    {
        $minutes = (int) $this->params->get('quota_cooldown', 60);
        $file    = $this->flagPath();

        return $minutes > 0 && is_file($file) && (time() - (int) @filemtime($file)) < $minutes * 60;
    }

    private function startQuotaCooldown(): void
    {
        if ((int) $this->params->get('quota_cooldown', 60) > 0) {
            @touch($this->flagPath());
        }
    }

    private function log(string $message, int $priority = Log::INFO): void
    {
        if (!$this->params->get('log', 1)) {
            return;
        }

        static $registered = false;

        if (!$registered) {
            Log::addLogger(['text_file' => 'plg_system_aialias.php'], Log::ALL, ['aialias']);
            $registered = true;
        }

        Log::add($message, $priority, 'aialias');
    }
}

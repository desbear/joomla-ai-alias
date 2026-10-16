<?php

/**
 * @package     AI Alias
 * @subpackage  plg_system_aialias
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\System\AiAlias\Service;

\defined('_JEXEC') or die;

final class AiException extends \RuntimeException
{
    /** @var string */
    public $reason;

    public function __construct(string $message, string $reason = '')
    {
        parent::__construct($message);

        $this->reason = $reason;
    }
}

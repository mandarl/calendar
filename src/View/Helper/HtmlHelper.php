<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\View\Helper\HtmlHelper as CoreHtmlHelper;

/**
 * Html helper with the Cake 3 `confirm` markup.
 *
 * Cake 5 renders `data-confirm-message="..."` and calls `this.dataset.confirmMessage`; Cake 3 (the baseline the
 * goldens were captured from) rendered the message inline: `onclick="if (confirm(&quot;msg&quot;)) { return true; } return false;"`
 * with the message passed through json_encode(). This restores that markup so `Html->link(..., ['confirm' => ...])`
 * and `Form->postLink()` (see FormHelper) match the baseline byte for byte.
 */
class HtmlHelper extends CoreHtmlHelper
{
    /**
     * @inheritDoc
     */
    public function link(array|string $title, array|string|null $url = null, array $options = []): string
    {
        if (array_key_exists('confirm', $options)) {
            $confirmMessage = $options['confirm'];
            unset($options['confirm']);
            if ($confirmMessage) {
                $options['onclick'] = $this->templater()->format('confirmJs', [
                    'confirmMessage' => $this->cleanConfirmMessage($confirmMessage),
                    'confirm' => $this->confirmCode((string)$confirmMessage, 'return true;', 'return false;', $options),
                ]);
            }
        }

        return parent::link($title, $url, $options);
    }

    /**
     * Cake 3 `Helper::_confirm()`: `if (confirm(<json message>)) { $okCode } $cancelCode`.
     *
     * @param string $message Confirmation text.
     * @param string $okCode Code run when the user confirms.
     * @param string $cancelCode Code run after the block (when the user cancels).
     * @param array<string, mixed> $options Link options (`escape => false` leaves the code unescaped).
     * @return string
     */
    protected function confirmCode(string $message, string $okCode, string $cancelCode = '', array $options = []): string
    {
        $message = $this->cleanConfirmMessage($message);
        $confirm = "if (confirm({$message})) { {$okCode} } {$cancelCode}";
        if (isset($options['escape']) && $options['escape'] === false) {
            $confirm = h($confirm);
        }

        return $confirm;
    }

    /**
     * Cake 3 `Helper::_cleanConfirmMessage()`.
     *
     * @param string $message Message.
     * @return string JSON string literal.
     */
    protected function cleanConfirmMessage(string $message): string
    {
        return str_replace('\\\n', '\n', (string)json_encode($message));
    }
}

<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Data;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * @phpstan-import-type AttachmentData from Attachment
 *
 * @phpstan-type CommentData array{
 *     id: scalar, type_id: scalar, ticket_id: scalar, sender_id: scalar,
 *     sender: scalar, subject: scalar|null, body: scalar, content_type: scalar,
 *     from: scalar, to: scalar|null, internal: scalar,
 *     created_by_id: scalar, updated_by_id: scalar, origin_by_id: scalar|null,
 *     attachments: array<int, AttachmentData>|\Traversable<int, AttachmentData>|null,
 *     updated_at: string|null, created_at: string|null
 * }
 */
class Comment
{
    use NormalizesJsonScalars;

    // Longer bodies are truncated only in body_filtered; body keeps the full text.
    private const MAX_INPUT_LENGTH = 200000;

    private const MAX_NESTING_DEPTH = 100;

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromJson(array $data): self
    {
        $data['id'] = self::jsonInt($data['id']);
        $data['type_id'] = self::jsonInt($data['type_id']);
        $data['ticket_id'] = self::jsonInt($data['ticket_id']);
        $data['sender_id'] = self::jsonInt($data['sender_id']);
        $data['created_by_id'] = self::jsonInt($data['created_by_id']);
        $data['updated_by_id'] = self::jsonInt($data['updated_by_id']);
        $data['sender'] = self::jsonString($data['sender']);
        $data['body'] = self::jsonString($data['body']);
        $data['content_type'] = self::jsonString($data['content_type']);
        $data['from'] = self::jsonString($data['from']);
        $data['internal'] = self::jsonBool($data['internal']);
        $data['origin_by_id'] = $data['origin_by_id'] === null ? null : self::jsonInt($data['origin_by_id']);
        $data['subject'] = $data['subject'] === null ? null : self::jsonString($data['subject']);
        $data['to'] = $data['to'] === null ? null : self::jsonString($data['to']);

        $attachments = $data['attachments'];

        if ($attachments !== null && ! is_array($attachments) && ! $attachments instanceof \Traversable) {
            throw new \TypeError('Expected attachments to be an array, Traversable, or null.');
        }

        $attachments = (new Collection($attachments ?? []))
            ->map(function (array $attachment) use ($data) {
                return Attachment::fromJson(
                    data: $attachment,
                    ticketId: $data['ticket_id'],
                    commentId: $data['id'],
                );
            });

        $filterImages = config('zammad.filter_images');
        $filterTables = config('zammad.filter_tables');
        $filterSigantureMarker = config('zammad.filter_signature_marker');
        $filterSigantureMarkerValue = config('zammad.filter_signature_marker_value');
        $filterDataSignature = config('zammad.filter_data_signature');
        $filterDataSignatureValue = config('zammad.filter_data_signature_value');

        $bodyFiltered = mb_strcut($data['body'], 0, self::MAX_INPUT_LENGTH, 'UTF-8');
        $isHtml = str_contains(strtolower($data['content_type']), 'html');

        if ($isHtml) {
            if ($filterSigantureMarker) {
                $bodyFiltered = Str::of($bodyFiltered)->before($filterSigantureMarkerValue)->toString();
            }

            if ($filterDataSignature) {
                $bodyFiltered = Str::of($bodyFiltered)->before($filterDataSignatureValue)->toString();
            }
        }

        if ($isHtml && ! self::exceedsMaxNestingDepth($bodyFiltered)) {
            $sanitizerConfig = (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->allowLinkSchemes(['http', 'https', 'mailto'])
                ->allowMediaSchemes(['http', 'https'])
                ->dropAttribute('srcset', '*')
                ->withMaxInputLength(self::MAX_INPUT_LENGTH);

            // These URL attributes are not handled by Symfony's URL sanitizer.
            foreach (['imagesrcset', 'manifest', 'usemap', 'conversiondestination', 'reportingorigin', 'resources', 'code', 'object'] as $attribute) {
                $sanitizerConfig = $sanitizerConfig->dropAttribute($attribute, '*');
            }

            foreach (['form', 'input', 'button', 'select', 'option', 'textarea', 'title', 'style', 'script', 'iframe', 'object', 'embed', 'template', 'plaintext', 'xmp', 'listing', 'noembed', 'noframes', 'noscript', 'svg'] as $element) {
                $sanitizerConfig = $sanitizerConfig->dropElement($element);
            }

            if ($filterImages) {
                $sanitizerConfig = $sanitizerConfig->dropElement('img');
            }

            if ($filterTables) {
                foreach (['table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption'] as $element) {
                    $sanitizerConfig = $sanitizerConfig->blockElement($element);
                }
            }

            $bodyFiltered = (new HtmlSanitizer($sanitizerConfig))->sanitize($bodyFiltered);
        } else {
            $bodyFiltered = htmlspecialchars($bodyFiltered, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return new self(
            id: $data['id'],
            type_id: $data['type_id'],
            ticket_id: $data['ticket_id'],
            sender_id: $data['sender_id'],
            sender: $data['sender'],
            subject: $data['subject'],
            body: $data['body'],
            body_filtered: $bodyFiltered,
            content_type: $data['content_type'],
            from: $data['from'],
            to: $data['to'],
            internal: $data['internal'],
            created_by_id: $data['created_by_id'],
            updated_by_id: $data['updated_by_id'],
            origin_by_id: $data['origin_by_id'],
            attachments: $attachments,
            updated_at: Carbon::parse($data['updated_at']),
            created_at: Carbon::parse($data['created_at']),
        );
    }

    private static function exceedsMaxNestingDepth(string $html): bool
    {
        $voidElements = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];
        $depth = 0;
        $offset = 0;
        $length = strlen($html);

        // Consume each tag once, without building or recursively walking a DOM.
        while (($offset = strpos($html, '<', $offset)) !== false) {
            if (substr_compare($html, '<!--', $offset, 4) === 0) {
                $end = strpos($html, '-->', $offset + 4);
                if ($end === false) {
                    break;
                }

                $offset = $end + 3;

                continue;
            }

            if (! preg_match('~\G<(/?)([a-z][a-z0-9:-]*)(?=[\s/>])~i', $html, $tag, 0, $offset)) {
                $offset++;

                continue;
            }

            $offset += strlen($tag[0]);
            $quote = null;
            $lastCharacter = '';

            while ($offset < $length) {
                $character = $html[$offset++];
                if ($quote !== null) {
                    if ($character === $quote) {
                        $quote = null;
                    }
                } elseif ($character === '"' || $character === "'") {
                    $quote = $character;
                } elseif ($character === '>') {
                    break;
                } elseif (! ctype_space($character)) {
                    $lastCharacter = $character;
                }
            }

            if ($lastCharacter === '/' || in_array(strtolower($tag[2]), $voidElements, true)) {
                continue;
            }

            if ($tag[1] === '/') {
                $depth = max(0, $depth - 1);
            } elseif (++$depth > self::MAX_NESTING_DEPTH) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Attachment>  $attachments
     */
    public function __construct(
        public int $id,
        public int $type_id,
        public int $ticket_id,
        public int $sender_id,
        public string $sender,
        public ?string $subject,
        public string $body,
        public string $body_filtered,
        public string $content_type,
        public string $from,
        public ?string $to,
        public bool $internal,
        public int $created_by_id,
        public int $updated_by_id,
        public ?int $origin_by_id,
        public Collection $attachments,
        public Carbon $updated_at,
        public Carbon $created_at,
    ) {}

    public static function fake(
        ?int $id = null,
        ?int $type_id = null,
        ?int $ticket_id = null,
        ?int $sender_id = null,
        ?string $sender = null,
        ?string $subject = null,
        ?string $body = null,
        ?string $content_type = null,
        ?string $from = null,
        ?string $to = null,
        ?bool $internal = null,
        ?int $created_by_id = null,
        ?int $updated_by_id = null,
        ?int $origin_by_id = null,
        ?Carbon $updated_at = null,
        ?Carbon $created_at = null,
    ): self {
        return new self(
            id: $id ?? random_int(1, 1000),
            type_id: $type_id ?? 10,
            ticket_id: $ticket_id ?? 1,
            sender_id: $sender_id ?? 1,
            sender: $sender ?? Arr::random(['Agent', 'Customer']),
            subject: $subject ?? 'Fake subject',
            body: $body ?? 'Fake body',
            body_filtered: $body ?? 'Fake body with filtered blockquote',
            content_type: $content_type ?? 'text/html',
            from: $from ?? 'Fake User',
            to: $to ?? 'Fake User',
            internal: $internal ?? false,
            created_by_id: $created_by_id ?? 1,
            updated_by_id: $updated_by_id ?? 1,
            origin_by_id: $origin_by_id ?? 1,
            attachments: collect([Attachment::fake()]),
            updated_at: $updated_at ?? now(),
            created_at: $created_at ?? now()->subDay(),
        );
    }

    public function fromName(): string
    {
        return Str::before(Str::between($this->from, '"', '"'), '<');
    }

    public function fromEmail(): string
    {
        return Str::between($this->from, '<', '>');
    }
}

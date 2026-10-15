<?php

namespace App\Services\Telephony;

use DOMDocument;
use DOMElement;

/**
 * Builds TwiML call instructions (the XML Twilio reads). Values are always escaped by the DOM.
 *
 *   TwiML::make()->say('Hello')->dial(['timeout' => 20], fn (DOMElement $d, TwiML $t) => $t->into($d, 'Client', 'u12'))->xml()
 */
final class TwiML
{
    private DOMDocument $doc;

    private DOMElement $root;

    private function __construct()
    {
        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->root = $this->doc->createElement('Response');
        $this->doc->appendChild($this->root);
    }

    public static function make(): self
    {
        return new self;
    }

    public function say(string $text): self
    {
        $this->into($this->root, 'Say', $text, ['voice' => 'Polly.Joanna']);

        return $this;
    }

    /**
     * @param  array<string, string|int|bool|null>  $attributes
     * @param  callable(DOMElement, self): void  $children
     */
    public function dial(array $attributes, callable $children): self
    {
        $dial = $this->into($this->root, 'Dial', null, $attributes);
        $children($dial, $this);

        return $this;
    }

    /** @param array<string, string|int|bool|null> $attributes */
    public function record(array $attributes): self
    {
        $this->into($this->root, 'Record', null, $attributes);

        return $this;
    }

    /** @param array<string, string|int|bool|null> $attributes */
    public function startTranscription(array $attributes): self
    {
        $start = $this->into($this->root, 'Start');
        $this->into($start, 'Transcription', null, $attributes);

        return $this;
    }

    public function hangup(): self
    {
        $this->into($this->root, 'Hangup');

        return $this;
    }

    /**
     * Append an element (attributes with null values are left out; booleans become "true"/"false").
     *
     * @param  array<string, string|int|bool|null>  $attributes
     */
    public function into(DOMElement $parent, string $name, ?string $text = null, array $attributes = []): DOMElement
    {
        $element = $this->doc->createElement($name);
        if ($text !== null) {
            $element->appendChild($this->doc->createTextNode($text));
        }
        foreach ($attributes as $key => $value) {
            if ($value !== null) {
                $element->setAttribute($key, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            }
        }
        $parent->appendChild($element);

        return $element;
    }

    public function xml(): string
    {
        return (string) $this->doc->saveXML();
    }
}

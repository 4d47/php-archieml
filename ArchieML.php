<?php

final class ArchieML
{
    const whitespacePattern = '\\x{0000}\\x{0009}\\x{000A}\\x{000B}\\x{000C}\\x{000D}\\x{0020}\\x{00A0}\\x{2000}\\x{2001}\\x{2002}\\x{2003}\\x{2004}\\x{2005}\\x{2006}\\x{2007}\\x{2008}\\x{2009}\\x{200A}\\x{200B}\\x{2028}\\x{2029}\\x{202F}\\x{205F}\\x{3000}\\x{FEFF}';
    const slugBlacklist = self::whitespacePattern . '\\x{005B}\\x{005C}\\x{005D}\\x{007B}\\x{007D}\\x{003A}';

    const nextLine = '/.*((\r|\n)+)/u';
    const startKey = '/^\\s*([^' . self::slugBlacklist . ']+)[ \t\r]*:[ \t\r]*(.*(?:\n|\r|$))/u';
    const commandKey = '/^\\s*:[ \t\r]*(endskip|ignore|skip|end).*?(\n|\r|$)/ui';
    const arrayElement = '/^\\s*\\*[ \t\r]*(.*(?:\n|\r|$))/u';
    const scopePattern =  '/^\\s*(\\[|\\{)[ \t\r]*([\+\.]*)[ \t\r]*([^' . self::slugBlacklist . ']*)[ \t\r]*(?:\\]|\\}).*?(\n|\r|$)/u';

    private $data;
    private $scope;
    private $stack;
    private $stackScope;
    private $bufferScope;
    private $bufferKey;
    private $bufferString;
    private $isSkipping;

    public $options = [
        'arrayClass' => 'ArrayObject',
        'comments' => false
    ];

    public function __construct(array $options = [])
    {
        $this->options = array_merge($this->options, $options);
    }

    public static function load($input, $options = [])
    {
        return (new self($options))->parse($input);
    }

    public function parse($input)
    {
        assert('is_string($input)');

        $this->initialize();

        while ($input) {
            // Inside the input stream loop, the `input` string is trimmed down as matches
            // are found, and fires a call to the matching parse*() function.

            if (preg_match(self::commandKey, $input, $matches)) {

                // echo 'parseCommandKey: ';var_export($matches);exit;
                $this->parseCommandKey($input, mb_strtolower($matches[1]));

            } else if (!$this->isSkipping && preg_match(self::startKey, $input, $matches) &&
                       (!$this->stackScope || $this->stackScope['arrayType'] !== 'simple')) {

                // echo 'parseStartKey: ';var_export($matches);exit;
                $this->parseStartKey($matches[1], $matches[2]);

            } else if (!$this->isSkipping && preg_match(self::arrayElement, $input, $matches) && $this->stackScope && $this->stackScope['array'] &&
                       ($this->stackScope['arrayType'] !== 'complex' && $this->stackScope['arrayType'] !== 'freeform') &&
                       strpos($this->stackScope['flags'], '+') === false) {

                // echo 'parseArrayElement: ';var_export($matches);exit;
                $this->parseArrayElement($matches[1]);

            } else if (!$this->isSkipping && preg_match(self::scopePattern, $input, $matches)) {

                // echo 'parseScope: ';var_export($matches);
                $this->parseScope($matches[1], $matches[2], $matches[3]);

            } else if (preg_match(self::nextLine, $input, $matches)) {
                // echo 'parseText: ';var_export($matches);exit;
                $this->parseText($input, $matches[0]);

            } else {
                // End of document reached
                $this->parseText($input, $input);
                $input = '';
            }

            if ($matches) {
                $input = mb_substr($input, mb_strlen($matches[0]));
            }
        }
        $this->flushBuffer();
        return $this->toArray();
    }

    private function initialize()
    {
        $this->data = $this->makeArray();
        $this->scope = $this->data;
        $this->stack = $this->makeArray();
        $this->stackScope = null;
        $this->bufferScope = null;
        $this->bufferKey = null;
        $this->bufferString = '';
        $this->isSkipping = false;
        $this->flushBuffer();
    }

    private function parseStartKey($key, $restOfLine)
    {
        // When a new key is encountered, the rest of the line is immediately added as
        // its value, by calling `flushBuffer`.
        $this->flushBuffer();

        $this->incrementArrayElement($key);

        if ($this->stackScope && strpos($this->stackScope['flags'], '+') !== false) {
            $key = 'value';
        }

        $this->bufferKey = $key;
        $this->bufferString = $restOfLine;

        $this->flushBufferInto($key, [ 'replace' => true ]);
    }

    private function parseArrayElement($value) {
        $this->flushBuffer();

        $this->stackScope['arrayType'] = $this->stackScope['arrayType'] ?: 'simple';

        $this->stackScope['array'][] = '';
        $this->bufferKey = $this->stackScope['array'];
        $this->bufferString = $value;
        $this->flushBufferInto($this->stackScope['array'], [ 'replace' => true ]);
    }

    private function parseCommandKey(&$input, $command) {
        // if isSkipping, don't parse any command unless :endskip

        if ($this->isSkipping && !($command === 'endskip' || $command === 'ignore')) {
            return $this->flushBuffer();
        }

        switch ($command) {
            case 'end':
                // When we get to an end key, save whatever was in the buffer to the last
                // active key.
                if ($this->bufferKey) {
                    $this->flushBufferInto($this->bufferKey, [ 'replace' => false ]);
                }
                return;

            case 'ignore':
                // When ":ignore" is reached, stop parsing immediately
                $input = '';
                break;

            case 'skip':
                $this->isSkipping = true;
                break;

            case 'endskip':
                $this->isSkipping = false;
                break;
        }

        $this->flushBuffer();
    }

    private function parseScope($scopeType, $flags, $scopeKey) {
        // Throughout the parsing, `scope` refers to one of the following:
        //   * `data`
        //   * an object - one level within `data` - when we're within a {scope} block
        //   * an object at the end of an array - which is one level within `data` -
        //     when we're within an [array] block.
        //
        // `scope` changes whenever a scope key is encountered. It also changes
        // within parseStartKey when we start a new object within an array.
        $this->flushBuffer();

        if ($scopeKey == '') {

            // Move up a level
            $lastStackItem = $this->pop($this->stack);
            $this->scope = ($lastStackItem ? $lastStackItem['scope'] : $this->data) ?: $this->data;
            $this->stackScope = $this->stack[$this->stack->count() - 1];

        } else if ($scopeType === '[' || $scopeType === '{') {
            $nesting = false;
            $keyScope = $this->data;

            // If the flags include ".", drill down into the appropriate scope.
            if (strpos($flags, '.') !== false) {
                $this->incrementArrayElement($scopeKey, $flags);
                $nesting = true;
                if ($this->stackScope) {
                    $keyScope = $this->scope;
                }

                // Otherwise, make sure we reset to the global scope
            } else {
                $this->scope = $this->data;
                $this->stack = $this->makeArray();
            }

            // Within freeforms, the `type` of nested objects and arrays is taken
            // verbatim from the `keyScope`.
            if ($this->stackScope && strpos($this->stackScope['flags'], '+') !== false) {
                $parsedScopeKey = $scopeKey;

            // Outside of freeforms, dot-notation interpreted as nested data.
            } else {
                $keyBits = explode('.', $scopeKey);
                for ($i = 0; $i < count($keyBits) - 1; $i++) {
                    $keyScope = $keyScope[$keyBits[$i]] = $keyScope[$keyBits[$i]] ?: $this->makeArray();
                }
                $parsedScopeKey = $keyBits[count($keyBits) - 1];
            }

            // Content of nested scopes within a freeform should be stored under "value."
            if ($this->stackScope && strpos($this->stackScope['flags'], '+') !== false && substr($flags, '.') !== false) {
                if ($scopeType === '[') {
                    $parsedScopeKey = 'value';
                } else if ($scopeType === '{') {
                    $this->scope = $this->scope['value'] = $this->makeArray();
                }
            }

            $stackScopeItem = $this->makeArray([
                'array' => null,
                'arrayType' => null,
                'arrayFirstKey' => null,
                'flags' => $flags,
                'scope' => $this->scope
            ]);
            if ($scopeType === '[') {

                $stackScopeItem['array'] = $keyScope[$parsedScopeKey] = $this->makeArray();
                if (strpos($flags, '+') !== false) {
                    $stackScopeItem['arrayType'] = 'freeform';
                }
                if ($nesting) {
                    $this->stack[] = $stackScopeItem;
                } else {
                    $this->stack = $this->makeArray();
                    $this->stack[] = $stackScopeItem;
                }
                $this->stackScope = $this->stack[$this->stack->count() - 1];

            } else if ($scopeType === '{') {
                if ($nesting) {
                    $this->stack[] = $stackScopeItem;
                } else {
                    // TODO: not sure about this typeof
                    $this->scope = $keyScope[$parsedScopeKey] = ($keyScope[$parsedScopeKey] instanceof $this->options['arrayClass']) ? $keyScope[$parsedScopeKey] : $this->makeArray();
                    $this->stack = $this->makeArray();
                    $this->stack[] = $stackScopeItem;
                }
                $this->stackScope = $this->stack[$this->stack->count() - 1];
            }
        }
    }

    private function parseText($input, $text) {
        if ($this->stackScope && strpos($this->stackScope['flags'], '+') !== false && preg_match('/[^\n\r\s]/', $text)) {
            $this->stackScope['array'][] = $this->makeArray(['type' => 'text', 'value' => trim($text) /* preg_replace('/(^\s*)|(\s*$)/g', $text, '') */ ]);
        } else {
            $this->bufferString .= substr($input, 0, mb_strlen($text));
        }
    }

    private function incrementArrayElement($key) {
        // Special handling for arrays. If this is the start of the array, remember
        // which key was encountered first. If this is a duplicate encounter of
        // that key, start a new object.

        if ($this->stackScope && $this->stackScope['array']) {
            // If we're within a simple array, ignore
            $this->stackScope['arrayType'] = $this->stackScope['arrayType'] ?: 'complex';
            if ($this->stackScope['arrayType'] === 'simple') return;

            // arrayFirstKey may be either another key, or null
            if ($this->stackScope['arrayFirstKey'] === null || $this->stackScope['arrayFirstKey'] === $key) {
                $this->stackScope['array'][] = $this->scope = $this->makeArray();
            }
            if (strpos($this->stackScope['flags'], '+') !== false) {
                $this->scope['type'] = $key;
            } else {
                $this->stackScope['arrayFirstKey'] = $this->stackScope['arrayFirstKey'] ?: $key;
            }
        }
    }

    private function formatValue($value, $type) {
        if ($this->options['comments']) {
            $value = preg_replace('/(?:^\\)?\[[^\[\]\n\r]*\](?!\])/mg', '', $value); // remove comments
            $value = preg_replace('/\[\[([^\[\]\n\r]*)\]\]/g', '[$1]', $value); // [[]] => []
        }

        if ($type == 'append') {
            // If we're appending to a multi-line string, escape special punctuation
            // by using a backslash at the beginning of any line.
            // Note we do not do this processing for the first line of any value.
            $value = preg_replace('/^(\\s*)\\\\/m', '$1', $value);
        }

        return $value;
    }

    private function flushBuffer() {
        $result = $this->bufferString . '';
        $this->bufferString = '';
        $this->bufferKey = null;
        return $result;
    }

    private function flushBufferInto($key, $options = []) {
        $existingBufferKey = $this->bufferKey;
        $value = $this->flushBuffer();

        if ($options['replace']) {
            $value = ltrim($this->formatValue($value, 'replace')); // preg_replace('/^\\s*/', $this->formatValue($value, 'replace'), '');
            preg_match('/\\s*$/', $value, $matches);
            $this->bufferString = $matches[0];
            $this->bufferKey = $existingBufferKey;
        } else {
            $value = $this->formatValue($value, 'append');
        }

        if ($key instanceof $this->options['arrayClass']) {
            // key is an array
            if ($options['replace']) {
                $key[$key->count() - 1] = '';
            }

            $key[$key->count() - 1] .= rtrim($value); // preg_replace('/\\s*$/', $value, '');

        } else {
            $keyBits = explode('.', $key);
            $this->bufferScope = $this->scope;

            for ($i = 0; $i < count($keyBits) - 1; $i++) {
                if (is_string($this->bufferScope[$keyBits[$i]])) {
                    $this->bufferScope[$keyBits[$i]] = $this->makeArray();
                }
                $this->bufferScope = $this->bufferScope[$keyBits[$i]] = $this->bufferScope[$keyBits[$i]] ?: $this->makeArray();
            }

            if ($options['replace']) {
                $this->bufferScope[$keyBits[count($keyBits) - 1]] = '';
            }

            $this->bufferScope[$keyBits[count($keyBits) - 1]] .= rtrim($value); // preg_replace('/\\s*$/', $value, '');
        }
    }

    private function makeArray($input = [])
    {
        return new $this->options['arrayClass']($input);
    }

    private function toArray($array = null)
    {
        $result = [];
        if (is_null($array)) {
            $array = $this->data;
        }
        foreach ($array as $key => $value) {
            $result[$key] = $value instanceof ArrayObject ? $this->toArray($value) : $value;
        }
        return $result;
    }

    private function pop($arrayObject) {
        $last = $arrayObject[$arrayObject->count() - 1];
        unset($arrayObject[$arrayObject->count() - 1]);
        return $last;
    }

    private static function stringResource($string)
    {
        $handle = fopen('php://memory', 'w+');
        fwrite($handle, $string);
        rewind($handle);
        return $handle;
    }

    private function debug()
    {
        echo "data: ";
        var_export($this->data);
        echo "\n\nscope: ";
        var_export($this->scope);
        echo "\n\nstack: ";
        var_export($this->stack);
        echo "\n\nstackScope: ";
        var_export($this->stackScope);
        echo "\n\nbufferScope: ";
        var_export($this->bufferScope);
        echo "\n\nbufferKey: ";
        var_export($this->bufferKey);
        echo "\n\nbufferString: ";
        var_export($this->bufferString);
        echo "\n\nisSkipping: ";
        var_export($this->isSkipping);
    }
}

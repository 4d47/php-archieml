<?php

class ArchieML
{
    const START_KEY = '/^\s*([A-Za-z0-9\-_\.]+)[ \t\r]*:[ \t\r]*(.*(?:\n|\r|$))/';
    const COMMAND_KEY = '/^\s*:[ \t\r]*(endskip|ignore|skip|end)(.*(?:\n|\r|$))/i';
    const ARRAY_ELEMENT = '/^\s*\*[ \t\r]*(.*(?:\n|\r|$))/';
    const SCOPE_PATTERN = '/^\s*(\[|\{)[ \t\r]*([A-Za-z0-9\-_\.]*)[ \t\r]*(?:\]|\}).*?(\n|\r|$)/';

    private $data;
    private $scope;
    private $bufferScope;
    private $bufferKey;
    private $bufferString;
    private $isSkipping;
    private $doneParsing;
    private $array;
    private $arrayType;
    private $arrayFirstKey;

    public function __construct()
    {
        $this->data = new ArrayObject();
        $this->scope = $this->data;
        $this->bufferScope = null;
        $this->bufferKey = null;
        $this->bufferString = '';
        $this->isSkipping  = false;
        $this->doneParsing = false;

        $this->flushScope();
    }

    public static function load($stream)
    {
        if (is_string($stream)) {
            $stream = static::stringResource($stream);
        }
        return (new self())->parse($stream);
    }

    public function parse($stream)
    {
        assert('is_resource($stream)');

        while ($line = fgets($stream)) {

            if ($this->doneParsing) {
                return $this->data;
            }

            if (preg_match(self::COMMAND_KEY, $line, $match)) {
                $this->parseCommandKey(strtolower($match[1]));

            } elseif (!$this->isSkipping && (preg_match(self::START_KEY, $line, $match)) && (is_null($this->array) || $this->arrayType != 'simple')) {
                $this->parseStartKey($match[1], isset($match[2]) ? $match[2] : '');

            } elseif (!$this->isSkipping && (preg_match(self::ARRAY_ELEMENT, $line, $match)) && (!is_null($this->array) && $this->arrayType != 'complex')) {
                $this->parseArrayElement($match[1]);

            } elseif (!$this->isSkipping && (preg_match(self::SCOPE_PATTERN, $line, $match))) {
                $this->parseScope($match[1], $match[2]);

            } else {
                $this->bufferString .= $line;
            }
        }

        $this->flushBuffer();
        return $this->toArray();
    }

    protected function parseStartKey($key, $rest_of_line)
    {
        $this->flushBuffer();

        if (!is_null($this->array)) {
            $this->arrayType = !is_null($this->arrayType) ? $this->arrayType : 'complex';

            # Ignore complex keys inside simple arrays
            if ($this->arrayType == 'simple') {
                return;
            }

            if (in_array($this->arrayFirstKey, [null, $key])) {
                $this->array[] = $this->scope = new ArrayObject();
            }

            $this->arrayFirstKey = !is_null($this->arrayFirstKey) ? $this->arrayFirstKey : $key;
        }

        $this->bufferKey = $key;
        $this->bufferString = $rest_of_line;

        $this->flushBufferInto($key, ['replace' => true]);
    }

    protected function parseArrayElement($value)
    {
        $this->flushBuffer();

        $this->arrayType = !is_null($this->arrayType) ? $this->arrayType : 'simple';

        # Ignore simple array elements inside complex arrays
        if ($this->arrayType == 'complex') {
            return;
        }

        $this->array[] = '';

        $this->bufferKey = $this->array;
        $this->bufferString = $value;
        $this->flushBufferInto($this->array, ['replace' => true]);
    }

    protected function parseCommandKey($command)
    {
        if ($this->isSkipping && !in_array($command, ['endskip', 'ignore'])) {
            return $this->flushBuffer();
        }

        switch ($command) {
        case 'end':
            if ($this->bufferKey) {
                $this->flushBufferInto($this->bufferKey, ['replace' => false]);
            }
            return;

        case 'ignore':
            return $this->doneParsing = true;

        case 'skip':
            $this->isSkipping = true;
            break;

        case 'endskip':
            $this->isSkipping = false;
        }
    }

    protected function parseScope($scopeType, $scopeKey)
    {
        $this->flushBuffer();
        $this->flushScope();

        if ($scopeKey == '') {
            $this->scope = $this->data;
        }

        elseif (in_array($scopeType, ['[', '{'])) {
            $keyScope = $this->data;
            $keyBits  = explode('.', $scopeKey);
            $lastBitIndex = count($keyBits) - 1;
            $lastBit = $keyBits[$lastBitIndex];

            for ($i = 0; $i < $lastBitIndex; $i++) {
                $bit = $keyBits[$i];
                $keyScope[$bit] = isset($keyScope[$bit]) ? $keyScope[$bit] : new ArrayObject();
                $keyScope = $keyScope[$bit];
            }

            if ($scopeType == '[') {
                if (empty($keyScope[$lastBit])) {
                    $keyScope[$lastBit] = new ArrayObject();
                }
                $this->array = $keyScope[$lastBit];

                if (is_string($this->array)) {
                    $keyScope[$lastBit] = new ArrayObject();
                    $this->array = $keyScope[$lastBit];
                }

                if ($this->array->count() > 0) {
                    $this->arrayType = is_string($this->array[0]) ? 'simple' : 'complex';
                }

            } elseif ($scopeType == '{') {
                if (empty($keyScope[$lastBit])) {
                    $keyScope[$lastBit] = new ArrayObject();
                }
                $this->scope = $keyScope[$lastBit];
            }
        }
    }

    protected function flushBuffer()
    {
        $result = $this->bufferString;
        $this->bufferString = '';
        return $result;
    }

    protected function flushBufferInto($key, array $options)
    {
        $value = $this->flushBuffer();

        if ($options['replace']) {
            $value = preg_replace('/^\s*/', '', $this->formatValue($value, 'replace'));
            preg_match('/\s*$/', $value, $match);
            $this->bufferString = $match[0];
        } else {
            $value = $this->formatValue($value, 'append');
        }

        if ($key instanceof ArrayObject) {
            if ($options['replace']) {
                $key[$key->count() - 1] = '';
            }
            $key[$key->count() - 1] .= preg_replace('/\s*$/', '', $value);

        } else {
            $keyBits = explode('.', $key);
            $lastBit = count($keyBits) - 1;
            $this->bufferScope = $this->scope;

            for ($i = 0; $i < $lastBit; $i++) {
                $bit = $keyBits[$i];
                if (isset($this->bufferScope[$bit]) && is_string($this->bufferScope[$bit])) { # reset
                    $this->bufferScope[$bit] = new ArrayObject();
                }
                if (!isset($this->bufferScope[$bit])) {
                    $this->bufferScope[$bit] = new ArrayObject();
                }
                $this->bufferScope = $this->bufferScope[$bit];
            }

            if ($options['replace']) {
                $this->bufferScope[$keyBits[$lastBit]] = '';
            }
            if (!isset($this->bufferScope[$keyBits[$lastBit]])) {
                $this->bufferScope[$keyBits[$lastBit]] = '';
            }
            $this->bufferScope[$keyBits[$lastBit]] .= preg_replace('/\s*$/', '', $value);
        }
    }

    protected function flushScope()
    {
        $this->array = $this->arrayType = $this->arrayFirstKey = $this->bufferKey = null;
    }

    /**
     * type can be either :replace or :append.
     * If it's :replace, then the string is assumed to be the first line of a
     * value, and no escaping takes place.
     * If we're appending to a multi-line string, escape special punctuation
     * by prepending the line with a backslash.
     * (:, [, {, *, \) surrounding the first token of any line.
     */
    protected function formatValue($value, $type)
    {
        $value = preg_replace('/\[[^\[\]\n\r]*\](?!\])/', '', $value); // remove comments
        $value = preg_replace('/\[\[([^\[\]\n\r]*)\]\]/', '[\1]', $value); # [[]] => []

        if ($type == 'append') {
            $value = preg_replace('/^(\s*)\\\\/m', '\1', $value);
        }

        return $value;
    }

    protected function toArray($array = null)
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

    public static function stringResource($string)
    {
        $handle = fopen('php://memory', 'w+');
        fwrite($handle, $string);
        rewind($handle);
        return $handle;
    }

}

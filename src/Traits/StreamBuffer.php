<?php

namespace Weird\Traits;

use Laravel\SerializableClosure\SerializableClosure;
use Weird\Contracts\Message;
use Weird\Messages\DataMessage;
use Weird\Messages\Events\Hint;
use Weird\Messages\ExecutableMessage;
use Weird\Messages\UnknownMessage;

trait StreamBuffer
{
    public const DELIMITER = "\00";

    protected array $buffer = [];

    public function buffer($stream): bool
    {
        $data = '';
        while ($str = stream_get_contents($stream)) {
            $data .= $str;
        }

        if (!$data) {
            return false;
        }

        $messages = [];
        $open = null;
        for ($i = 0; $i < strlen($data); $i++){
            if ($data[$i] === static::DELIMITER) {
                if ($open) {
                    $messages[] = $open;
                    $open = null;
                } else {
                    $open = '';
                }
                continue;
            }

            if (is_string($open)) {
                $open .= $data[$i];
            }
        }

        foreach ($messages as $item) {
            $data = str_replace(static::DELIMITER . $item . static::DELIMITER, '', $data);
        }

        $this->buffer = array_merge(
            $this->buffer,
            array_map(
                function (mixed $value) {
                    $obj = @unserialize($value);

                    if ($obj instanceof Message) {
                        return $obj;
                    }

                    if ($obj instanceof SerializableClosure) {
                        return new ExecutableMessage($obj);
                    }

                    return new DataMessage($value);
                },
                $messages
            ) + ($data ? [new UnknownMessage($data)] : [])
        );

        return true;
    }

    public function readStream($stream): ?Message
    {
        $this->buffer($stream);
        return array_shift($this->buffer);
    }

    public function writeStream($stream, mixed $message): bool
    {
        return fwrite($stream, static::wrap($message));
    }

    public static function wrap(mixed $input): string
    {
        return static::DELIMITER . serialize($input) . static::DELIMITER;
    }
}

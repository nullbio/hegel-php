<?php

declare(strict_types=1);

namespace Hegel\Protocol;

use CBOR\ByteStringObject;
use CBOR\Decoder;
use CBOR\Encoder;
use CBOR\ListObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\StringStream;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use UnexpectedValueException;

final class CborCodec
{
    public static function encode(mixed $value): string
    {
        return (new Encoder())->encode($value);
    }

    public static function decode(string $payload): mixed
    {
        return self::decodeObject(
            Decoder::create()->decode(StringStream::create($payload)),
        );
    }

    private static function decodeObject(object $object): mixed
    {
        if ($object instanceof UnsignedIntegerObject || $object instanceof NegativeIntegerObject) {
            return self::decodeInteger($object->getValue());
        }

        if ($object instanceof ByteStringObject || $object instanceof TextStringObject) {
            return $object->getValue();
        }

        if ($object instanceof ListObject) {
            $values = [];

            foreach ($object as $item) {
                $values[] = self::decodeObject($item);
            }

            return $values;
        }

        if ($object instanceof MapObject) {
            $values = [];

            foreach ($object as $item) {
                $key = self::decodeObject($item->getKey());

                if (! is_int($key) && ! is_string($key)) {
                    throw new UnexpectedValueException('Decoded CBOR map key cannot be represented as a PHP array key.');
                }

                $values[$key] = self::decodeObject($item->getValue());
            }

            return $values;
        }

        if (method_exists($object, 'normalize')) {
            return $object->normalize();
        }

        throw new UnexpectedValueException(sprintf('Unsupported CBOR object: %s', $object::class));
    }

    private static function decodeInteger(string $value): int|string
    {
        $negative = str_starts_with($value, '-');
        $digits = $negative ? substr($value, 1) : $value;
        $digits = ltrim($digits, '0');

        if ($digits === '') {
            return 0;
        }

        $limit = $negative
            ? ltrim((string) PHP_INT_MIN, '-')
            : (string) PHP_INT_MAX;

        if (strlen($digits) < strlen($limit)) {
            return (int) $value;
        }

        if (strlen($digits) > strlen($limit)) {
            return $value;
        }

        if ($digits <= $limit) {
            return (int) $value;
        }

        return $value;
    }
}

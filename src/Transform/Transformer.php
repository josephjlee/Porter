<?php
declare(strict_types=1);

namespace ScriptFUSION\Porter\Transform;

use ScriptFUSION\Porter\Collection\RecordCollection;

/**
 * Provides a method to transform imported data.
 */
interface Transformer extends AnysyncTransformer
{
    /**
     * Transforms the specified record collection, decorated with the specified context data.
     *
     * @param RecordCollection $records Record collection.
     * @param mixed $context Context data.
     */
    public function transform(RecordCollection $records, $context): RecordCollection;
}

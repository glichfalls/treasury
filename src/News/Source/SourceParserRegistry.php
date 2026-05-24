<?php

namespace App\News\Source;

use App\Entity\AssetNewsSource;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves which parser handles a source: the first tagged {@see SourceParser}
 * whose supports() matches, else the DefaultSourceParser. The default is also
 * tagged (it implements the interface), so it's filtered out of the iterator by
 * identity to avoid it claiming everything before a bespoke parser is reached.
 */
final class SourceParserRegistry
{
    /**
     * @param iterable<SourceParser> $parsers
     */
    public function __construct(
        #[AutowireIterator('app.news_source_parser')]
        private readonly iterable $parsers,
        private readonly DefaultSourceParser $default,
    ) {}

    public function resolve(AssetNewsSource $source): SourceParser
    {
        foreach ($this->parsers as $parser) {
            if ($parser === $this->default) {
                continue; // fallback only
            }
            if ($parser->supports($source)) {
                return $parser;
            }
        }
        return $this->default;
    }
}

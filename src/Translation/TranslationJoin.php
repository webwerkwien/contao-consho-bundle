<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Translation;

/**
 * The one place where the fallback rule is expressed as SQL.
 *
 * Why not in PHP after loading: the listing sorts by title and pages with
 * LIMIT/OFFSET. A fallback in PHP would mean loading every product and then
 * sorting — which would undo the entire query design from B-3.
 *
 * In the default language, NO join is created. A monolingual shop therefore
 * gets exactly the previous query — that is the regression boundary of the
 * whole multilingual feature, not an optimisation on the side.
 *
 * The five values sit in the constructor, not on the methods. The reason is
 * not convenience: $alias and $joinAlias are two equally valid strings, and
 * swapped they produce, depending on the names, either a loud SQL error or a
 * valid, semantically inverted query that silently returns wrong values.
 * Named once, they can no longer slip per call.
 */
final class TranslationJoin
{
    /**
     * The parent table does not appear as a literal in the join SQL — its
     * value comes from params(). Otherwise the SQL would carry a table name
     * that came from a caller instead of a binding.
     */
    public function __construct(
        private readonly string $ptable,
        private readonly string $language,
        private readonly string $fallback,
        private readonly string $alias,
        private readonly string $joinAlias = 't',
    ) {
    }

    /**
     * The LEFT JOIN fragment, or '' in the default language.
     *
     * The five values are available as properties rather than as arguments —
     * they are a decision, not a set of arguments. Passing them individually
     * means they can be passed wrongly, individually.
     */
    public function sql(): string
    {
        if ($this->isFallbackLanguage()) {
            return '';
        }

        return \sprintf(
            'LEFT JOIN tl_consho_translation %1$s ON %1$s.pid = %2$s.id AND %1$s.ptable = :%1$s_ptable AND %1$s.language = :%1$s_lang',
            $this->joinAlias,
            $this->alias,
        );
    }

    /**
     * @return array<string, string>
     */
    public function params(): array
    {
        if ($this->isFallbackLanguage()) {
            return [];
        }

        return [$this->joinAlias.'_ptable' => $this->ptable, $this->joinAlias.'_lang' => $this->language];
    }

    /**
     * The expression for a field: the translation, otherwise its own text.
     *
     * NULLIF in front, because an existing translation row with an empty
     * field must not count as a translation — COALESCE alone would take the
     * empty string as a value and make the title disappear.
     */
    public function coalesce(string $field): string
    {
        if ($this->isFallbackLanguage()) {
            return $this->alias.'.'.$field;
        }

        return \sprintf('COALESCE(NULLIF(%s.%s, \'\'), %s.%s)', $this->joinAlias, $field, $this->alias, $field);
    }

    private function isFallbackLanguage(): bool
    {
        return $this->language === $this->fallback || '' === $this->language;
    }
}

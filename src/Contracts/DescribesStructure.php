<?php

namespace Flowiteam\PublicaConnector\Contracts;

/**
 * What this site is made of, in PUBLICA's vocabulary.
 *
 * PUBLICA keeps a mirror of every destination's sections, labels and bylines,
 * and files an article by rules the customer set once — "the roasting cluster
 * goes in Coffee, ten a month under this byline". None of that can happen for
 * a site that cannot be asked what it has, which is why an article arriving
 * here used to land unfiled and wait for a person.
 *
 * **Two taxonomy names are not free-form.** PUBLICA understands `category` and
 * `post_tag`: its rules are keyed on the first and its tag matcher on the
 * second. A site that answers `sections` describes itself perfectly and gets
 * nothing filed. The names come from WordPress because that is where the
 * mirror was first built, and renaming them now would mean a migration on
 * every customer's stored rules.
 *
 * **Nothing here is a promise to create anything.** The mirror exists so that
 * PUBLICA can *choose among* what a site already has; a term it cannot find is
 * dropped, never invented. A blog that quietly grows a thousand tags is a mess
 * discovered six months later, and no log line is ever written about a tag
 * that was created successfully.
 */
interface DescribesStructure
{
    /**
     * @param  string|null  $locale  What PUBLICA is publishing in, when it said.
     *                               A site whose sections are per-language
     *                               answers with that language's; one where
     *                               they are not ignores this.
     * @return array{
     *     terms: list<array{taxonomy: string, remote_id: string|int, name: string, slug?: string|null, parent_remote_id?: string|int|null, count?: int}>,
     *     authors: list<array{remote_id: string|int, name: string, slug?: string|null}>,
     * }
     */
    public function describeStructure(?string $locale = null): array;
}

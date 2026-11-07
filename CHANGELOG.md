# Changelog

## 1.1.3 — 2026-11-07

- "Change template or class" offers only the templates of the kind of resource being changed (those used
  mostly on items, item sets or media, and those not used yet), and refuses one used for another kind: a
  media template was offered for items. No database change.

## 1.1.2 — 2026-11-06

- The edit form no longer looks for the save question of an older module that Revision History
  replaced; Revision History's own question is used as before. No database change.

## 1.1.1

- A run that adds or changes a link now asks the Inverse Properties module to keep the other side
  of it, as Omeka's own item form does (`inverse_properties_set_inverses`). Before, a link added by
  a run (to "Related item", say) had no link back until somebody saved the item in the form. Without
  that module installed nothing changes.
- And before that module looks, the records linked read their values again: a run that saves one
  item twice (one entry per value added) made the link back twice, since the module looks at what
  Doctrine holds in memory.

## 1.1.0

What the Bulk Edit module (3.4.40) does and Batch Values did not, so Bulk Edit can be turned off.
Every new task is previewed first, logged, written through Omeka's API (so a module that records
saves, such as Revision History, sees each change) and can be cancelled from the log.

- **Fill from an authority** (*Fix the text of values*): fill the empty labels of links from the
  service the link points to, set every label to the service's name, or find the link of a text.
  Value Suggest is asked first when it is on, with its own services and settings; otherwise the
  link's own page is read, for links of id.loc.gov, wikidata.org, geonames.org, idref.fr,
  vocab.getty.edu, d-nb.info (GND), orcid.org and ror.org only (no other address is ever opened).
  A link is taken from a text only when exactly one suggestion has that text. The services are
  asked during the preview and their answers are kept with the run, so the run writes what the
  preview showed. At most 200 questions per run; skipped values say why.
- **Plain text from HTML** (*Fix the text of values*): HTML values (and, if ticked, XML values and
  Text values with tags pasted in) keep only their words, as Text; paragraphs become new lines.
- **Media by renderer**: *Make media public or private*, *Edit media details*, *Make thumbnails
  again*, *Turn images* and *Delete media* can be limited to the media shown by a renderer
  (file, html, youtube, oembed, iiif…), alone or with the kinds of media.
- **Set or remove the thumbnail**: a condition on the primary media (the one chosen on the item,
  else its first media): it has no image of its own, it has one, or it is of a kind ticked.
- **Make page images from PDFs**: *Let other modules process the page images* (on by default).
  Unticked, the pages are made without the API's events, as Bulk Edit's "process events": text
  extraction, search indexing or tiles of other modules are skipped; files, thumbnails, the log and
  Revision History's record are made all the same.
- New choices show their words in a second language beside the English when *Configure → Second
  language beside the labels* is set (e.g. `th`; Thai is in `language/th.po`), with the details
  behind a "?".
- A cancel that put deleted media back (with their kept files) can itself be cancelled: the media
  are deleted again, their files kept aside. Until now it was skipped as "changed since".
- The sentence of a run says the media filters and the thumbnail's conditions as they work
  together: "the media of type image/jpeg shown by file", "… — Only those with a primary media
  of the kind application/pdf".

Found while trying 1.1.0 on a copy of a real archive, fixed before release:

- *2. What to do* no longer fails with an error on every selection.
- Getty labels: the service redirects to an address with `:443`, which it then refuses; the
  redirects are now followed without the default port.
- *Set every label* with a language: a label is marked with that language only when the authority
  has a name in it; the main name, used when it has none, keeps the value's language (the preview
  says so).
- *Find the link from the text*: "no suggestion has this text (N with other words)" instead of
  "N suggestions fit" when none has the same text.

**Upgrade:** replace the folder, then *Admin → Modules → Batch Values → Upgrade*. No table changes.
For the Thai words: *Configure → Second language beside the labels* → `th`.

## 1.0.2 and before

See the README and https://github.com/phumraphee/BatchValues/releases.

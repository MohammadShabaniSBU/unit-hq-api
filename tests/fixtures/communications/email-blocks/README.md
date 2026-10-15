# Email block golden fixtures

Rendered output targets **Outlook desktop** (table layout, inline styles,
bulletproof VML buttons). Do not "prettify" these files — byte equality is the
contract for `BlockRendererTest`.

v1 documents render through the v2 path (`EmailBlockDocument::upcast()`): one
full-width section, padding `md`, mobile stacking on. HTML goldens include that
section wrapper (head style, section cell, MSO ghost table, column div). Block
rows inside a `100` section keep the previous padding. Text goldens are unchanged.

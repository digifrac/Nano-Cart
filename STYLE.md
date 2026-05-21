# Nano Cart writing style

Conventions for prose in this repository. Applies to README, FORMAT, ARCHITECTURE, INSTALL, CHANGELOG, CONTRIBUTING, code comments, commit messages, and any other text written into the repo.

## No double-length hyphens, ever

Do not use any dash character that is wider than a single ASCII hyphen.

**Forbidden:**

| Character | Name | Unicode |
|-----------|------|---------|
| `—` | em-dash | U+2014 |
| `–` | en-dash | U+2013 |
| `--` | two ASCII hyphens used as a stylistic dash | (sequence) |

*(The forbidden characters appear in the table above and in a few other places below because they are the subject being defined. Showing forbidden characters inside table cells, inline code, or code blocks for demonstration purposes is the only exempt use.)*

**Allowed:**

| Character | Use |
|-----------|-----|
| `-` | single ASCII hyphen for compound adjectives (`mobile-first`), product names (`Ko-fi`), and numeric ranges (`20-50`) |

**Use instead of a long dash** (pick the one that fits the sentence):

- **Period** for two related independent thoughts: `It works. Here's how.`
- **Comma** for a brief aside: `It works, with one caveat.`
- **Colon** for a label followed by detail: `Status: shipping.` or `[FORMAT.md](FORMAT.md): on-disk format specification`
- **Parentheses** for a true parenthetical: `It works (mostly).`

### Exemptions: markdown and CLI syntax

These uses of multiple hyphens are syntax, not punctuation, and are allowed:

- **Markdown horizontal rules:** `---` on its own line
- **Markdown table separator rows:** `|------|-----|`
- **YAML front-matter delimiters:** `---` opening and closing a front-matter block
- **CLI long-option flags in code blocks or inline code:** `rsync --delete`, `git --version`, `php --info`
- **Markdown setext headings** (underlined headings): `===` and `---` underlines

The rule applies to **prose dashes used as punctuation**, not to functional syntax that happens to contain hyphens.

### The footer attribution string

The locked footer wording for the unlicensed-shop attribution is:

> Powered by Nano Cart. Developed by Digital Fracture.

Two short sentences separated by a period. Earlier draft material may show an em-dash variant (`Powered by Nano Cart — Developed by Digital Fracture`). That variant is superseded by this style rule and must not appear in the rendered footer, in README references to it, in admin previews, or in licence verification test cases.

### Why this rule exists

Consistent punctuation is easier to read across the project, easier to grep, and easier to type on a UK keyboard without a dedicated em-dash key. The rule also avoids the common AI-generated-text tell of em-dash-heavy prose, which matters for a commercial product where author voice is part of the brand.

### Enforcing the rule

Before committing any markdown or text change, grep the affected file for the forbidden characters:

```
grep -nE '—|–|--' file.md
```

(The `--` regex will also flag legitimate CLI flag examples. Visually inspect the results and ignore matches inside code blocks or inline code.)

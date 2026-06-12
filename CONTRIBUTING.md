# Contributing to Nano Cart

Nano Cart is solo development and, as of v1.5.0, **feature-complete and feature-locked**: ongoing work is limited to bug fixes, security patches, code cleanup, and documentation. Bug reports and feedback are very welcome. Formal contribution guidelines (PR templates, code review process, branching strategy) stay lightweight by design.

---

## What is useful right now

### Bug reports

The most valuable thing you can do is install Nano Cart on a real site and report any rough edges. File issues at [github.com/digifrac/Nano-Cart/issues](https://github.com/digifrac/Nano-Cart/issues).

Helpful bug reports include:

- The version (see `VERSION` file or `core.php`'s `NANO_CART_VERSION` constant)
- PHP version (`php --version`)
- Host environment (Apache + mod_rewrite version, or nginx, or shared hosting brand)
- Steps to reproduce, including which page or admin action triggered the bug
- Expected behaviour vs actual behaviour
- Browser dev tools output if it's a frontend bug
- PHP error log excerpts if it's a backend bug

### Feature suggestions

The feature set is locked as of v1.5.0, so new features are not being accepted: keeping the surface small is the point of the product. You are still welcome to open an issue to discuss an idea, but the honest default answer is "out of scope." Read the "Not for you" and "Roadmap" sections of [README.md](README.md) first.

What is always useful: ideas that make the *existing* code smaller, clearer, faster, or safer without adding surface area.

### Security issues

Please open a **private security advisory** via GitHub's security tab, not a public issue. Allow a reasonable window for a fix to ship before public disclosure.

---

## Pull requests

PRs are welcome but please open an issue first to discuss the change. The codebase is small enough that an opinion on the design will save you implementation time if the change isn't a fit.

Coding conventions:

- Match the existing hand-written, lean style. No frameworks, no Composer dependencies (Parsedown is the single vendored exception, single file).
- PHP 8.1+ features encouraged (match expressions, readonly properties, named arguments). No backwards-compat shims for older PHP.
- All CSS classes use the `nano-cart-` prefix (frontend) or `nano-cart-admin-` prefix (admin). No `cart-` shorthand anywhere.
- All POST handlers validate CSRF before processing.
- All file paths validated to prevent directory traversal.
- All user input escaped before output.
- File header comments describe what the file does and how it's loaded.
- Prose style: no em-dashes, en-dashes, or double-hyphens as punctuation. See [STYLE.md](STYLE.md).

---

## Local development

Clone the repo. Copy `bootstrap.example.php` to `bootstrap.php` and point the path constants at a local config directory and the in-repo `seed-data/` content folders. Start PHP's built-in server:

```bash
php -S 127.0.0.1:8000 -t .
```

Visit `http://127.0.0.1:8000/admin/setup.php` to run the setup wizard.

---

## Other ways to support the project

- Share the project with developers who build static client sites
- Write a blog post about your installation experience
- [Buy me a coffee](https://buymeacoffee.com/digitalfracture) if Nano Cart saves you time
- Buy a per-domain licence (single £29 / agency 3-pack £69 / agency unlimited £249) to use the project on commercial client work without the footer attribution. See [digitalfracture.co.uk/nano.php](https://www.digitalfracture.co.uk/nano.php).

---

## Code of conduct

Be respectful. Critique code and ideas, not people. Assume good faith. Don't engage in harassment of any kind. The project owner reserves the right to lock issues, delete comments, or block contributors who violate these basic norms.

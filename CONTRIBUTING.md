# Contributing to Nano Cart

Nano Cart is early-stage solo development. Bug reports and feedback are very welcome. Formal contribution guidelines (PR templates, code review process, branching strategy) are deferred until the project stabilises after v1.0.0 has been live on a few real deployments.

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

Open an issue with the `feature` label. Be specific: who is the operator, what are they trying to do, what would the proposed feature change about their workflow. Suggestions that align with Nano Cart's "deliberately not a general-purpose e-commerce platform" stance are more likely to land.

Read the "Not for you" and "Roadmap" sections of [README.md](README.md) first to see whether your feature is already explicitly out of scope.

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
- Buy a per-domain licence (single £29 / agency 3-pack £69 / agency unlimited £249) to use the project on commercial client work without the footer attribution. See [digitalfracture.co.uk/nano-cart.html](https://digitalfracture.co.uk/nano-cart.html).

---

## Code of conduct

Be respectful. Critique code and ideas, not people. Assume good faith. Don't engage in harassment of any kind. The project owner reserves the right to lock issues, delete comments, or block contributors who violate these basic norms.

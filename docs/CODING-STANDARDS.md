# PHP structure and readability

Keep new behavior in focused modules with a clear responsibility. Each command belongs in its own file under `src/Server/Commands/`. Keep command registration, dispatch, shared argument parsing and permission policy separate from individual handlers. The existing command modules and shared registry are described in [COMMANDS.md](COMMANDS.md); complete native command semantics remain tracked in #10.

Use descriptive names, four spaces for indentation, explicit types and readable control flow. Put one statement on each line, use spaces around operators and after commas, and separate methods with blank lines. Expand long argument lists across lines. Avoid compressed conditionals and a growing central switch statement.

The shared `.php-cs-fixer.dist.php` configuration uses PSR-12 with explicit spacing and method separation. Formatting never enables risky fixers. Use PHP CS Fixer 3.95.27 for reproducible formatting; its official release PHAR SHA-256 is `47231757eefdd08778e2bd047d98a63dfaad881006c8c1d1bc870d63be7f6a9b`.

```sh
php /path/to/php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php path/to/changed-file.php
php /path/to/php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php --dry-run --diff path/to/changed-file.php
```

Format the complete PHP files touched by a change, including tests. Review the result for clear names and long expressions: a formatter does not replace that review. Older untouched files still need gradual cleanup. Re-run the checks appropriate to the changed behavior after formatting.

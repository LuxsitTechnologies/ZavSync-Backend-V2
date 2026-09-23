<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## MySQL staging approval

The automated test suite currently runs on SQLite. Before any staging or production approval, run the accounting integration suite against the target MySQL version and explicitly verify concurrent journal, invoice, customer-receipt, purchase-order, goods-receipt, supplier-bill, and supplier-payment numbering; idempotent retries; simultaneous receipts against the same purchase-order line; simultaneous bill posting; simultaneous customer and supplier payments; supplier-payment allocations; simultaneous FIFO issues and transfers against the same item/warehouse layers; row-lock behavior; deadlock retries; and transaction rollback behavior.

## Inventory costing and purchase timing

ZavSync uses FIFO with integer minor units and quantities stored in thousandths. A Stage 4 purchase receipt creates immutable stock movements and provisional FIFO layers at the PO line's net taxable cost. Partial-receipt cost is allocated from cumulative integer totals so the final receipt absorbs rounding and the complete PO line reconciles exactly.

Receipts do not create an AP liability or a speculative payable journal. When the linked supplier bill posts, the inventory asset debit uses the same provisional PO cost; any difference between supplier-bill net cost and provisional PO cost posts to the item's inventory adjustment / purchase-price-variance account. This keeps FIFO and the inventory asset ledger reconcilable without rewriting receipt history. A reviewed cost change that belongs in stock is recorded through the explicit `COST_ADJUSTMENT` action, which changes the open FIFO layer and posts the matching inventory/adjustment journal through `JournalPostingService`.

Customer returns restore the historical cost consumed by the original invoice stock issue and reverse COGS. Supplier returns reduce FIFO stock and credit inventory against the configured return-clearing/adjustment account; a future AP credit-note workflow must clear that balance and must not create a parallel supplier bill. Until the supplier bill posts, the reconciliation report deliberately exposes received-but-unbilled valuation as a difference rather than hiding it.

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

=== Woocommerce osCommerce Sync ===
Contributors: alexwing,dave111223
Plugin link: http://aaranda.es/woocommerce-oscommerce-sync/
Tags: woocommerce, oscommerce, import, sync
Requires at least: 3.5.1
Tested up to: 4.9.4–es_ES
Stable tag: 2.0.1
License: AGPLv3.0 or later
License URI: http://opensource.org/licenses/AGPL-3.0

Woocommerce osCommerce Sync allows you to import products, categories, customers, orders and pages directly from osCommerce to Woocommerce

== Description ==
NOTES:

1. You must install the main "WooCommerce - excelling eCommerce" plugin in order to use this plugin
2. You must have MySQL remote access to the osCommerce database (most hosting providers will allow you to setup remote access)

After installing the plugin go to Woocommerce -> osCommerce Import and enter the osCommerce database info, and the URL to the osCommerce store.

All products and categories will be imported at once (import is not staggered yet).  If you have a lot of products to import and it is timing out just run the importer several times until all products are imported (products that have already been imported are skipped over).

If there is enough demand I may added a staggering system to avoid timeouts.

Note that when importing customers the passwords from osCommerce are NOT copied over due to the MD5+Salt encryption.  New passwords are generated, imported customers will need to do a forgotten password to get their new password.

== Installation ==

1. Unzip/Extract the plugin zip to your computer
2. Upload `\woocommerce-osc-import\` folder to the `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to Woocommerce -> osCommerce Sync

== Frequently Asked Questions ==

= What osCommerce data is imported? =

Categories and hierarchy including:
- Category Name
- Category Image

Products including:
- Product Name
- Product Description
- Product Image
- Product Price
- Product stock
- Product attributes
- Special prices

Customers including:
- Address/Email/Phone/Name etc...
- *NOTE* Passwords cannot be imported (due to the salted MD5s in osCommerce), so passwords are randomly generated upon import

Orders including:
- Products
- Customers info (addresses etc...)

Information Pages:
- Only if you are running CRE Loaded, or have installed the Info Pages addon for osCommerce

= What data is not imported? =

Some data that is NOT yet supported includes:
- Best Sellers

== Screenshots ==

1. http://aaranda.es/wp-content/uploads/2016/10/WooCommerce-import.jpg

== Changelog ==
= 2.0.1 =

* Add debug mode
* bootstrap 
* Lang selector
* limit and offset for produts

= 1.2.2 =

* Divide import categories/products in two sections 
* Change image import, now import only one resource if two products use same image.
* Add import second image product
* Verify if a image was before imported.
* Option for delete products images asociated.


= 1.2.1 =

* Fixed error handling of category imports
* Fixed issue with products from multiple categories being imported twice

= 1.2 =

* Added information page imports (if your running CRE Loaded or have info pages installed for osCommerce)

= 1.1 =

* Added customer and order import functionality

= 1.0 =

* Initial Woocommerce osCommerce import release

== Upgrade Notice ==

= 1. =
* Initial Woocommerce osCommerce import release


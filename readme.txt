=== CatCode Returns & Exchanges Manager for WooCommerce ===
Contributors: catcodestudio
Tags: returns, rma, exchange, refund, warranty claims
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Return, exchange and warranty claim requests for WooCommerce: My Account and guest form, return or claim period, statuses, waybills, e-mails.

== Description ==

Customers stop writing "how do I return this?" in chats and e-mails. They open the order, pick the items and quantities, choose a reason and what they want — a refund or an exchange — and send the request. The shop sees every request in one list, changes its status, answers the customer and tracks the return parcel.

**Free**

* "Return / exchange" button on eligible orders and a "Returns" tab in My Account.
* Guest form (shortcode `[catcode_returns_form]`, the page is created in one click): the customer finds the order by its number and the e-mail or phone used at checkout.
* Return window — 14 days after the order is completed by default (Ukrainian consumer law, EU right of withdrawal), configurable; 0 = no limit.
* Claims for defects instead of returns (for example the Czech reklamace): a claim period in months, 24 by default, 0 = no limit; a deadline to resolve each claim, 30 days from the day it is filed by default, shown in the request list, on the request page, in My Account and in the e-mails.
* What the customer can ask for: refund, exchange, repair or price reduction, each switched on in the settings.
* Only orders in the statuses you choose; quantities already refunded or requested cannot be requested twice.
* Your own list of reasons and your return conditions above the form.
* Request list with status filters and search, request page with history, a message to the customer and a private note.
* Statuses: new, approved, item received, money refunded, replacement sent, repaired, price reduction granted, rejected, closed. The date a request reaches an end status is kept as the date it was resolved.
* Return waybill with a tracking link where the carrier has one — entered by the customer or by the shop. Nova Poshta and Ukrposhta for a store in Ukraine; Zásilkovna (Packeta), PPL, Česká pošta and DPD for a store in Czechia or Slovakia; another carrier anywhere.
* E-mails to the customer (request received, every status change) in the WooCommerce template and in the language of the order; e-mail to the shop about each new request; order notes. For claims the confirmation carries the date of the claim, the items, the defect, the requested resolution and the contact for the answer, and the e-mail about the result carries the date and the way the claim was resolved, with the reason of a rejection.
* Box with the order's requests on the order edit screen (HPOS compatible).


== Installation ==

1. Upload the plugin and activate it (WooCommerce must be active).
2. Open WooCommerce → Returns settings: set the return period, order statuses and reasons.
3. Click "Create the page" to publish the guest form, or put `[catcode_returns_form]` on any page.
4. Requests appear under WooCommerce → Returns.


== Frequently Asked Questions ==

= Does the customer need an account? =
No. Guests use the page with the `[catcode_returns_form]` shortcode and find their order by number plus e-mail or phone.

= Can the same item be requested twice? =
No. Quantities in open or finished requests and quantities already refunded are subtracted. A rejected request frees its quantity again.


== Changelog ==

= 1.1.1 =
* Fix: e-mails to the customer went out in the language of the admin's profile instead of the store language when the order carried no language of its own. They now use the language of the order, or the site language.

= 1.1.0 =
* Claims for defects (for example the Czech reklamace): choose "Claims" in the settings to get a claim period in months (24 by default, 0 = no limit) and a deadline to resolve each claim (30 days from the day it is filed by default).
* The deadline is fixed when the claim is filed and shown in the request list, on the request page, in My Account and in the e-mails; the date a request is resolved is recorded.
* New resolutions the customer can ask for: repair and price reduction, with the statuses "Repaired" and "Price reduction granted".
* Claim e-mails: the confirmation says when the claim was filed, what it is about, which resolution is requested and where the answer goes; the result e-mail gives the date and the way it was resolved, and a rejection always carries its reason.
* Czech, Slovak stores: Zásilkovna (Packeta), PPL, Česká pošta and DPD for return waybills.


= 1.0.0 =
* First release.

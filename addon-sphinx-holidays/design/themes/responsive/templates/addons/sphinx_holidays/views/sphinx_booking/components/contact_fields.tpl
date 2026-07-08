{*
 * Shared Contact Fields Component
 *
 * Reusable contact info fields for all booking forms (hotel, package,
 * circuit, experience). Styled by travel_core's booking-pages.css.
 *
 * @package SphinxHolidays
 * @since 1.1.0
 *}

<div class="travel-contact-section contact-section">
    <h3 class="travel-section-title"><i class="icon-envelope"></i> {__("travel_core.contact_info")|default:"Contact Information"}</h3>
    <div class="travel-guest-grid">
        <div class="travel-guest-field">
            <label for="sphinx_contact_email">{__("travel_core.email")|default:"Email"}</label>
            <input type="email" id="sphinx_contact_email" name="contact[email]" class="ty-input-text" placeholder="email@example.com">
        </div>
        <div class="travel-guest-field">
            <label for="sphinx_contact_phone">{__("travel_core.phone")|default:"Phone"}</label>
            <input type="tel" id="sphinx_contact_phone" name="contact[phone]" class="ty-input-text" placeholder="+40...">
        </div>
    </div>
</div>

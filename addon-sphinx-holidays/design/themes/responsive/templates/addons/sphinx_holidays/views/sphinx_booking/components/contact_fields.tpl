{*
 * Shared Contact Fields Component
 *
 * Reusable contact info fields for all booking forms (hotel, circuit, experience).
 *
 * @package SphinxHolidays
 * @since 1.1.0
 *}

<div class="contact-section" style="margin-top: 20px; padding: 15px; background: #f5f9fc; border-radius: 6px;">
    <h3><i class="icon-envelope"></i> {__("travel_core.contact_info")|default:"Contact Information"}</h3>
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 200px;">
            <label>{__("travel_core.email")|default:"Email"}</label>
            <input type="email" name="contact[email]" class="ty-input-text" placeholder="email@example.com">
        </div>
        <div style="flex: 1; min-width: 200px;">
            <label>{__("travel_core.phone")|default:"Phone"}</label>
            <input type="tel" name="contact[phone]" class="ty-input-text" placeholder="+40...">
        </div>
    </div>
</div>

{**
 * Homepage Booking Engine - Booking.com Style v2.6.42
 * Uses same React booking form as product detail page
 * Includes "Where are you going?" destination field
 *}

{style src="css/addons/novoton_holidays/styles.css"}

<div class="novoton-homepage-booking" style="max-width: 1200px; margin: 0 auto; padding: 20px;">
    
    <h2 style="font-size: 28px; font-weight: 700; color: #1a1a1a; margin: 0 0 20px; text-align: center;">
        {__("novoton_holidays.find_your_next_stay")|default:"Find your next stay"}
    </h2>
    
    {* React-based booking form with destination field *}
    <div id="novoton-homepage-form-root" 
         data-mode="homepage"
         data-hotel-id=""
         data-product-id=""
         data-lang="{$smarty.const.CART_LANGUAGE|default:'en'}">
    </div>
    
</div>

{* Load React for booking form (same as product page) *}


<script>
window.NovotonTranslations = {
    availability: "{__('novoton_holidays.availability')|default:'Availability'}",
    bookYourStay: "{__('novoton_holidays.find_your_next_stay')|default:'Find your next stay'}",
    checkIn: "{__('novoton_holidays.check_in')|default:'Check-in'}",
    checkOut: "{__('novoton_holidays.check_out')|default:'Check-out'}",
    selectDates: "{__('novoton_holidays.select_dates')|default:'Select dates'}",
    guests: "{__('novoton_holidays.guests')|default:'Guests'}",
    adults: "{__('novoton_holidays.adults')|default:'adults'}",
    children: "{__('novoton_holidays.children')|default:'children'}",
    rooms: "{__('novoton_holidays.rooms')|default:'rooms'}",
    done: "{__('novoton_holidays.done')|default:'Done'}",
    room: "{__('novoton_holidays.room')|default:'Room'}",
    search: "{__('novoton_holidays.search')|default:'Search'}",
    addRoom: "{__('novoton_holidays.add_room')|default:'Add Room'}",
    whereAreYouGoing: "{__('novoton_holidays.where_going')|default:'Where are you going?'}",
    night: "{__('novoton_holidays.night')|default:'night'}",
    nights: "{__('novoton_holidays.nights')|default:'nights'}",
    childrensAges: "{__('novoton_holidays.childrens_ages')|default:'Children ages'}"
};
</script>
<script src="{$config.current_location}/js/addons/novoton_holidays/react19-bundle.js?v={$smarty.now}"></script>

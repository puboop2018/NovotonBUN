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
    childrenAges: "{__('novoton_holidays.childrens_ages')|default:"Children's ages"}",
    childAge: "{__('novoton_holidays.child_age')|default:"Child's age at check-in"}",
    selectAge: "{__('novoton_holidays.select_age')|default:'Select age'}",
    adult: "{__('novoton_holidays.adult')|default:'adult'}",
    child: "{__('novoton_holidays.child')|default:'child'}",
    yearsOld: "{__('novoton_holidays.years_old')|default:'years old'}",
    yearOld: "{__('novoton_holidays.year_old')|default:'year old'}",
    childLabel: "{__('novoton_holidays.child_label')|default:'Child'}",
    ageOfChild: "{__('novoton_holidays.age_of_child')|default:'Age of child'}",
    checkInPast: "{__('novoton_holidays.check_in_past')|default:'Check-in date cannot be in the past'}",
    includesOnRequest: "{__('novoton_holidays.includes_on_request')|default:'(includes on-request)'}",
    of: "{__('novoton_holidays.of')|default:'of'}",
    selected: "{__('novoton_holidays.selected')|default:'selected'}",
    selectedSingular: "{__('novoton_holidays.selected_singular')|default:'selected'}",
    selectCheckOut: "{__('novoton_holidays.select_check_out')|default:'Select check-out date'}",
    selectCheckIn: "{__('novoton_holidays.select_check_in')|default:'Select check-in date'}",
    pleaseEnterDates: "{__('novoton_holidays.please_enter_dates')|default:'Please select check-in and check-out dates'}",
    selectDatesMessage: "{__('novoton_holidays.select_dates_message')|default:"Select dates to see this property's availability and prices"}",
    pleaseSelectAllRooms: "{__('novoton_holidays.please_select_all_rooms')|default:'Please select a room type for each room'}",
    remove: "{__('novoton_holidays.remove')|default:'Remove'}",
    january: "{__('novoton_holidays.january')|default:'January'}",
    february: "{__('novoton_holidays.february')|default:'February'}",
    march: "{__('novoton_holidays.march')|default:'March'}",
    april: "{__('novoton_holidays.april')|default:'April'}",
    may: "{__('novoton_holidays.may')|default:'May'}",
    june: "{__('novoton_holidays.june')|default:'June'}",
    july: "{__('novoton_holidays.july')|default:'July'}",
    august: "{__('novoton_holidays.august')|default:'August'}",
    september: "{__('novoton_holidays.september')|default:'September'}",
    october: "{__('novoton_holidays.october')|default:'October'}",
    november: "{__('novoton_holidays.november')|default:'November'}",
    december: "{__('novoton_holidays.december')|default:'December'}",
    mon: "{__('novoton_holidays.mon')|default:'Mo'}",
    tue: "{__('novoton_holidays.tue')|default:'Tu'}",
    wed: "{__('novoton_holidays.wed')|default:'We'}",
    thu: "{__('novoton_holidays.thu')|default:'Th'}",
    fri: "{__('novoton_holidays.fri')|default:'Fr'}",
    sat: "{__('novoton_holidays.sat')|default:'Sa'}",
    sun: "{__('novoton_holidays.sun')|default:'Su'}"
};
</script>
<script src="{$config.current_location}/js/addons/novoton_holidays/react19-bundle.js?v=2.8.0" defer></script>

/**
 * Novoton Booking Form - React Component
 * Version: 1.0
 * 
 * Features:
 * - Dual-month calendar with range selection
 * - Multi-room support with add/remove
 * - Per-room adults/children with age selection
 * - Meal plan selection
 * - Integrates with CS-Cart form submission
 */

const { useState, useEffect, useRef } = React;

// Calendar Component
const Calendar = ({ checkIn, checkOut, onDateSelect, onClose }) => {
    const [currentMonth, setCurrentMonth] = useState(new Date());
    const calendarRef = useRef(null);
    
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];
    const dayNames = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
    
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    const renderMonth = (monthOffset) => {
        const date = new Date(currentMonth);
        date.setMonth(date.getMonth() + monthOffset);
        
        const year = date.getFullYear();
        const month = date.getMonth();
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);
        const startDay = (firstDay.getDay() + 6) % 7; // Monday = 0
        
        const days = [];
        
        // Empty cells for days before month starts
        for (let i = 0; i < startDay; i++) {
            days.push(<div key={`empty-${i}`} className="calendar-day empty"></div>);
        }
        
        // Days of month
        for (let day = 1; day <= lastDay.getDate(); day++) {
            const dayDate = new Date(year, month, day);
            const isDisabled = dayDate < today;
            const isCheckIn = checkIn && dayDate.toDateString() === checkIn.toDateString();
            const isCheckOut = checkOut && dayDate.toDateString() === checkOut.toDateString();
            const isInRange = checkIn && checkOut && dayDate > checkIn && dayDate < checkOut;
            const isToday = dayDate.toDateString() === today.toDateString();
            
            let className = 'calendar-day';
            if (isDisabled) className += ' disabled';
            if (isToday) className += ' today';
            if (isCheckIn) className += ' selected range-start';
            if (isCheckOut) className += ' selected range-end';
            if (isInRange) className += ' in-range';
            
            days.push(
                <div
                    key={day}
                    className={className}
                    onClick={() => !isDisabled && onDateSelect(dayDate)}
                >
                    {day}
                </div>
            );
        }
        
        return (
            <div className="calendar-month">
                <div className="calendar-month-header">
                    {monthOffset === 0 && (
                        <button type="button" onClick={() => setCurrentMonth(new Date(currentMonth.setMonth(currentMonth.getMonth() - 1)))}>‹</button>
                    )}
                    <span className="month-title">{monthNames[month]} {year}</span>
                    {monthOffset === 1 && (
                        <button type="button" onClick={() => setCurrentMonth(new Date(currentMonth.setMonth(currentMonth.getMonth() + 1)))}>›</button>
                    )}
                </div>
                <div className="calendar-grid">
                    {dayNames.map(d => <div key={d} className="day-header">{d}</div>)}
                    {days}
                </div>
            </div>
        );
    };
    
    const nights = checkIn && checkOut ? Math.round((checkOut - checkIn) / (1000 * 60 * 60 * 24)) : 0;
    
    return (
        <div className="calendar-popup" ref={calendarRef}>
            <div className="calendar-container">
                {renderMonth(0)}
                {renderMonth(1)}
            </div>
            <div className="calendar-footer">
                <span className="nights-info">
                    {nights > 0 ? <><strong>{nights} night{nights > 1 ? 's' : ''}</strong> selected</> : 'Select check-out date'}
                </span>
                <button type="button" className="btn-done" onClick={onClose}>Done</button>
            </div>
        </div>
    );
};

// Room Component
const RoomSection = ({ room, roomIndex, totalRooms, onChange, onRemove, validationError }) => {
    const roomRef = useRef(null);
    const updateRoom = (field, value) => {
        onChange(roomIndex, { ...room, [field]: value });
    };
    
    const updateChildAge = (childIndex, age) => {
        const newAges = [...room.childrenAges];
        newAges[childIndex] = age;
        updateRoom('childrenAges', newAges);
    };
    
    const adjustChildren = (delta) => {
        const newCount = Math.max(0, Math.min(6, room.children + delta));
        const newAges = [...room.childrenAges];
        while (newAges.length < newCount) newAges.push(null);
        while (newAges.length > newCount) newAges.pop();
        onChange(roomIndex, { ...room, children: newCount, childrenAges: newAges });
    };
    
    // Expose ref for scrolling
    useEffect(() => {
        if (roomRef.current) {
            roomRef.current.scrollToRoom = () => {
                roomRef.current.scrollIntoView({ behavior: 'smooth', block: 'center' });
            };
        }
    }, []);
    
    const hasError = validationError && validationError.roomIndex === roomIndex;
    
    return (
        <div className={`room-section ${hasError ? 'validation-error' : ''}`} ref={roomRef} data-room-index={roomIndex}>
            <div className="room-header">
                <span className="room-title">🏨 Room {roomIndex + 1}</span>
                {totalRooms > 1 && (
                    <button type="button" className="room-remove" onClick={() => onRemove(roomIndex)}>✕</button>
                )}
            </div>
            
            <div className="guest-row">
                <div className="guest-label">
                    <span>Adults</span>
                    <small>18+</small>
                </div>
                <div className="guest-controls">
                    <button type="button" disabled={room.adults <= 1} onClick={() => updateRoom('adults', room.adults - 1)}>−</button>
                    <span className="guest-count">{room.adults}</span>
                    <button type="button" disabled={room.adults >= 10} onClick={() => updateRoom('adults', room.adults + 1)}>+</button>
                </div>
            </div>
            
            <div className="guest-row">
                <div className="guest-label">
                    <span>Children</span>
                    <small>0-17</small>
                </div>
                <div className="guest-controls">
                    <button type="button" disabled={room.children <= 0} onClick={() => adjustChildren(-1)}>−</button>
                    <span className="guest-count">{room.children}</span>
                    <button type="button" disabled={room.children >= 6} onClick={() => adjustChildren(1)}>+</button>
                </div>
            </div>
            
            {room.children > 0 && (
                <div className={`children-ages ${hasError ? 'has-error' : ''}`}>
                    <label>Children's ages:</label>
                    {room.childrenAges.map((age, idx) => {
                        const isMissingAge = age === null || age === undefined || age === '';
                        const isErrorChild = hasError && validationError.childIndex === idx;
                        return (
                            <select
                                key={idx}
                                value={age ?? ''}
                                onChange={(e) => updateChildAge(idx, e.target.value ? parseInt(e.target.value) : null)}
                                className={isErrorChild ? 'error-highlight' : (isMissingAge ? 'missing-age' : '')}
                            >
                                <option value="">Select age</option>
                                {[...Array(18)].map((_, a) => (
                                    <option key={a} value={a}>{a} year{a !== 1 ? 's' : ''} old</option>
                                ))}
                            </select>
                        );
                    })}
                    {hasError && (
                        <div className="validation-message">⚠️ Please select age for child {validationError.childIndex + 1}</div>
                    )}
                </div>
            )}
        </div>
    );
};

// Main Booking Form Component
const NovotonBookingForm = ({ hotelId, productId, translations = {} }) => {
    // State
    const [checkIn, setCheckIn] = useState(null);
    const [checkOut, setCheckOut] = useState(null);
    const [rooms, setRooms] = useState([{ adults: 2, children: 0, childrenAges: [] }]);
    const [mealPlan, setMealPlan] = useState('');
    const [showCalendar, setShowCalendar] = useState(false);
    const [showGuests, setShowGuests] = useState(false);
    const [validationError, setValidationError] = useState(null);
    
    const guestsDropdownRef = useRef(null);
    
    const formRef = useRef(null);
    const calendarFieldRef = useRef(null);
    const guestsFieldRef = useRef(null);
    
    // Default translations
    const t = {
        bookYourStay: 'Book Your Stay',
        checkIn: 'Check-in',
        checkOut: 'Check-out',
        selectDates: 'Select dates',
        guests: 'Guests',
        mealPlan: 'Meal Plan',
        allBoards: 'All Boards',
        roomOnly: 'Room Only',
        bedBreakfast: 'Bed & Breakfast',
        halfBoard: 'Half Board',
        fullBoard: 'Full Board',
        allInclusive: 'All Inclusive',
        search: 'Search',
        addRoom: 'Add room',
        adults: 'adults',
        children: 'children',
        rooms: 'rooms',
        done: 'Done',
        ...translations
    };
    
    // Set default dates on mount
    useEffect(() => {
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        tomorrow.setHours(0, 0, 0, 0);
        
        const weekLater = new Date(tomorrow);
        weekLater.setDate(weekLater.getDate() + 7);
        
        setCheckIn(tomorrow);
        setCheckOut(weekLater);
    }, []);
    
    // Click outside handler
    useEffect(() => {
        const handleClickOutside = (e) => {
            if (calendarFieldRef.current && !calendarFieldRef.current.contains(e.target)) {
                setShowCalendar(false);
            }
            if (guestsFieldRef.current && !guestsFieldRef.current.contains(e.target)) {
                setShowGuests(false);
            }
        };
        
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);
    
    // Date selection handler
    const handleDateSelect = (date) => {
        if (!checkIn || (checkIn && checkOut) || date < checkIn) {
            setCheckIn(date);
            setCheckOut(null);
        } else {
            setCheckOut(date);
            setTimeout(() => setShowCalendar(false), 300);
        }
    };
    
    // Room handlers
    const updateRoom = (index, room) => {
        const newRooms = [...rooms];
        newRooms[index] = room;
        setRooms(newRooms);
        // Clear validation error when user makes changes
        if (validationError && validationError.roomIndex === index) {
            setValidationError(null);
        }
    };
    
    const addRoom = () => {
        if (rooms.length < 5) {
            setRooms([...rooms, { adults: 2, children: 0, childrenAges: [] }]);
        }
    };
    
    const removeRoom = (index) => {
        if (rooms.length > 1) {
            setRooms(rooms.filter((_, i) => i !== index));
            setValidationError(null);
        }
    };
    
    // Validate child ages - find first room with missing child age
    const validateChildAges = () => {
        for (let roomIdx = 0; roomIdx < rooms.length; roomIdx++) {
            const room = rooms[roomIdx];
            if (room.children > 0) {
                for (let childIdx = 0; childIdx < room.childrenAges.length; childIdx++) {
                    const age = room.childrenAges[childIdx];
                    if (age === null || age === undefined || age === '') {
                        return { roomIndex: roomIdx, childIndex: childIdx };
                    }
                }
            }
        }
        return null;
    };
    
    // Handle form submission with validation
    const handleSubmit = (e) => {
        const error = validateChildAges();
        if (error) {
            e.preventDefault();
            setValidationError(error);
            
            // Open guests dropdown if not already open
            setShowGuests(true);
            setShowCalendar(false);
            
            // Scroll to the room with error after dropdown opens
            setTimeout(() => {
                const roomElement = guestsDropdownRef.current?.querySelector(`[data-room-index="${error.roomIndex}"]`);
                if (roomElement) {
                    roomElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    // Add visual pulse effect
                    roomElement.classList.add('error-pulse');
                    setTimeout(() => roomElement.classList.remove('error-pulse'), 2000);
                }
            }, 100);
            
            return false;
        }
        return true;
    };
    
    // Computed values
    const totalAdults = rooms.reduce((sum, r) => sum + r.adults, 0);
    const totalChildren = rooms.reduce((sum, r) => sum + r.children, 0);
    const nights = checkIn && checkOut ? Math.round((checkOut - checkIn) / (1000 * 60 * 60 * 24)) : 7;
    
    // Format date display
    const formatDate = (date) => {
        if (!date) return '';
        const options = { weekday: 'short', day: 'numeric', month: 'short' };
        return date.toLocaleDateString('en-GB', options);
    };
    
    const dateDisplay = checkIn && checkOut
        ? `${formatDate(checkIn)} — ${formatDate(checkOut)}`
        : t.selectDates;
    
    // Guests display
    let guestsDisplay = '';
    if (rooms.length > 1) guestsDisplay += `${rooms.length} ${t.rooms}, `;
    guestsDisplay += `${totalAdults} ${t.adults}`;
    if (totalChildren > 0) guestsDisplay += `, ${totalChildren} ${t.children}`;
    
    // Collect all children ages
    const allChildrenAges = rooms.flatMap(r => r.childrenAges.filter(a => a !== null));
    
    return (
        <div className="novoton-booking-react">
            <h3 className="booking-title">{t.bookYourStay}</h3>
            
            <form ref={formRef} method="get" action="" className="booking-form" onSubmit={handleSubmit}>
                {/* Hidden fields for CS-Cart */}
                <input type="hidden" name="dispatch" value="novoton_booking.search" />
                <input type="hidden" name="hotel_id" value={hotelId || ''} />
                <input type="hidden" name="product_id" value={productId || ''} />
                <input type="hidden" name="check_in" value={checkIn ? checkIn.toISOString().split('T')[0] : ''} />
                <input type="hidden" name="nights" value={nights} />
                <input type="hidden" name="adults" value={totalAdults} />
                <input type="hidden" name="children" value={totalChildren} />
                <input type="hidden" name="children_ages" value={allChildrenAges.join(',')} />
                <input type="hidden" name="rooms" value={rooms.length} />
                <input type="hidden" name="room_data" value={JSON.stringify(rooms)} />
                <input type="hidden" name="meal_plan" value={mealPlan} />
                
                <div className="booking-form-row">
                    {/* Date Field */}
                    <div className="form-field date-field" ref={calendarFieldRef}>
                        <label>{t.checkIn} — {t.checkOut}</label>
                        <input
                            type="text"
                            readOnly
                            value={dateDisplay}
                            onClick={() => { setShowCalendar(!showCalendar); setShowGuests(false); }}
                        />
                        {showCalendar && (
                            <Calendar
                                checkIn={checkIn}
                                checkOut={checkOut}
                                onDateSelect={handleDateSelect}
                                onClose={() => setShowCalendar(false)}
                            />
                        )}
                    </div>
                    
                    {/* Guests Field */}
                    <div className="form-field guests-field" ref={guestsFieldRef}>
                        <label>{t.guests}</label>
                        <input
                            type="text"
                            readOnly
                            value={guestsDisplay}
                            onClick={() => { setShowGuests(!showGuests); setShowCalendar(false); }}
                        />
                        {showGuests && (
                            <div className="guests-dropdown" ref={guestsDropdownRef}>
                                {rooms.map((room, idx) => (
                                    <RoomSection
                                        key={idx}
                                        room={room}
                                        roomIndex={idx}
                                        totalRooms={rooms.length}
                                        onChange={updateRoom}
                                        onRemove={removeRoom}
                                        validationError={validationError}
                                    />
                                ))}
                                
                                {rooms.length < 5 && (
                                    <button type="button" className="add-room-btn" onClick={addRoom}>
                                        + {t.addRoom}
                                    </button>
                                )}
                                
                                <button type="button" className="btn-done" onClick={() => setShowGuests(false)}>
                                    {t.done}
                                </button>
                            </div>
                        )}
                    </div>
                    
                    {/* Meal Plan */}
                    <div className="form-field">
                        <label>{t.mealPlan}</label>
                        <select value={mealPlan} onChange={(e) => setMealPlan(e.target.value)}>
                            <option value="">{t.allBoards}</option>
                            <option value="RO">{t.roomOnly}</option>
                            <option value="BB">{t.bedBreakfast}</option>
                            <option value="HB">{t.halfBoard}</option>
                            <option value="FB">{t.fullBoard}</option>
                            <option value="AI">{t.allInclusive}</option>
                        </select>
                    </div>
                    
                    {/* Search Button */}
                    <div className="form-field btn-field">
                        <button type="submit" className="btn-search">{t.search}</button>
                    </div>
                </div>
            </form>
        </div>
    );
};

// Export for global use
window.NovotonBookingForm = NovotonBookingForm;

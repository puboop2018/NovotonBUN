/**
 * Novoton Booking Engine - Guest / Room picker
 *
 * Lets the user configure rooms, adults per room, children per room,
 * and select child ages. Supports add/remove room and per-child age
 * error highlighting.
 */

import { useCallback, useEffect, useMemo, useRef } from 'react';
import { t, getLocale } from './utils';
import { TrashIcon } from './icons';

export default function GuestPicker({
    rooms,
    maxRooms = 12,
    maxAdults = 9,
    maxChildren = 4,
    onUpdate,
    onClose,
    ageErrors = [],
    triggerRef,
}) {
    const popupRef = useRef(null);

    // Close on outside click or Escape key
    useEffect(() => {
        function handleClick(e) {
            // Ignore clicks on the trigger button (prevents close-then-reopen flicker)
            if (triggerRef && triggerRef.current && triggerRef.current.contains(e.target)) return;
            if (popupRef.current && !popupRef.current.contains(e.target)) {
                onClose && onClose();
            }
        }
        function handleKeyDown(e) {
            if (e.key === 'Escape') {
                onClose && onClose();
            }
        }
        document.addEventListener('mousedown', handleClick);
        document.addEventListener('keydown', handleKeyDown);
        return () => {
            document.removeEventListener('mousedown', handleClick);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [onClose, triggerRef]);

    const updateRoom = useCallback((roomIdx, field, value) => {
        const updated = rooms.map((room, i) => {
            if (i !== roomIdx) return room;

            const newRoom = { ...room, [field]: value };

            // When children count changes, adjust childrenAges array
            if (field === 'children') {
                const currentAges = room.childrenAges || [];
                if (value > currentAges.length) {
                    // Add empty ages
                    newRoom.childrenAges = [
                        ...currentAges,
                        ...Array(value - currentAges.length).fill(null),
                    ];
                } else {
                    // Trim
                    newRoom.childrenAges = currentAges.slice(0, value);
                }
            }

            return newRoom;
        });
        onUpdate(updated);
    }, [rooms, onUpdate]);

    const setChildAge = useCallback((roomIdx, childIdx, age) => {
        const updated = rooms.map((room, i) => {
            if (i !== roomIdx) return room;
            const ages = [...(room.childrenAges || [])];
            ages[childIdx] = age;
            return { ...room, childrenAges: ages };
        });
        onUpdate(updated);
    }, [rooms, onUpdate]);

    const addRoom = useCallback(() => {
        if (rooms.length >= maxRooms) return;
        onUpdate([...rooms, { adults: 2, children: 0, childrenAges: [] }]);
    }, [rooms, maxRooms, onUpdate]);

    const removeRoom = useCallback((roomIdx) => {
        if (rooms.length <= 1) return;
        onUpdate(rooms.filter((_, i) => i !== roomIdx));
    }, [rooms, onUpdate]);

    const handleDone = useCallback(() => {
        onClose && onClose();
    }, [onClose]);

    function hasAgeError(roomIdx, childIdx) {
        return ageErrors.some(e => e.room === roomIdx && e.child === childIdx);
    }

    // Compute missing ages: total count + which rooms (1-indexed) have missing ages
    const missingAgeInfo = useMemo(() => {
        let totalMissing = 0;
        const roomNumbers = [];
        rooms.forEach((room, idx) => {
            if (room.children > 0) {
                let roomHasMissing = false;
                (room.childrenAges || []).forEach(age => {
                    if (age === null || age === undefined || age === '') {
                        totalMissing++;
                        roomHasMissing = true;
                    }
                });
                if (roomHasMissing) {
                    roomNumbers.push(idx + 1);
                }
            }
        });
        return { totalMissing, roomNumbers };
    }, [rooms]);

    // Scroll to first empty age select and highlight it
    const scrollToMissingAge = useCallback(() => {
        if (!popupRef.current) return;
        const container = popupRef.current.querySelector('.nvt-guest-rooms-container');
        if (!container) return;

        const allSelects = container.querySelectorAll('.nvt-child-age-select');
        for (const sel of allSelects) {
            if (sel.value === '') {
                sel.closest('.nvt-room-section').scrollIntoView({ behavior: 'smooth', block: 'center' });
                sel.focus();
                sel.classList.add('nvt-age-highlight');
                sel.addEventListener('animationend', () => {
                    sel.classList.remove('nvt-age-highlight');
                }, { once: true });
                break;
            }
        }
    }, []);

    // Build smart anchor text for missing ages
    const smartAnchorText = useMemo(() => {
        const { totalMissing, roomNumbers } = missingAgeInfo;
        if (totalMissing === 0) return '';

        const roomLabel = t('room', 'Room');
        const roomList = roomNumbers.join(', ');
        const locale = getLocale();

        if (totalMissing === 1) {
            const childWord = locale === 'ro' ? 'copil' : 'child';
            return t('selectAgeForOneChild', `Select age for 1 ${childWord} (${roomLabel} [rooms]).`)
                .replace('[rooms]', roomList);
        }

        const childWord = locale === 'ro' ? 'copii' : 'children';
        return t('selectAgeForChildren', `Select age for [count] ${childWord} (${roomLabel} [rooms]).`)
            .replace('[count]', totalMissing)
            .replace('[rooms]', roomList);
    }, [missingAgeInfo]);

    return (
        <div className="nvt-guest-popup" ref={popupRef} role="dialog" aria-modal="true" aria-label={t('guestsAndRooms', 'Guests and rooms')}>
            {/* Smart Anchor: clickable alert for missing child ages */}
            {missingAgeInfo.totalMissing > 0 && (
                <button
                    type="button"
                    className="nvt-smart-age-anchor"
                    onClick={scrollToMissingAge}
                >
                    {smartAnchorText}
                </button>
            )}

            <div className="nvt-guest-rooms-container">
                {rooms.map((room, roomIdx) => (
                    <div key={roomIdx} className="nvt-room-section" data-room-idx={roomIdx}>
                        <div className="nvt-room-header">
                            <h4>{t('room', 'Room')} {roomIdx + 1}</h4>
                            {rooms.length > 1 && (
                                <button
                                    type="button"
                                    className="nvt-remove-room"
                                    onClick={() => removeRoom(roomIdx)}
                                    title={t('remove', 'Remove')}
                                >
                                    <TrashIcon />
                                    <span>{t('remove', 'Remove')}</span>
                                </button>
                            )}
                        </div>

                        {/* Adults */}
                        <div className="nvt-guest-row">
                            <div className="nvt-guest-label">
                                {t('adults', 'Adults')}
                                <small>18+</small>
                            </div>
                            <div className="nvt-guest-controls">
                                <button
                                    type="button"
                                    className="nvt-guest-btn"
                                    disabled={room.adults <= 1}
                                    onClick={() => updateRoom(roomIdx, 'adults', room.adults - 1)}
                                    aria-label={`${t('removeAdult', 'Remove 1 adult')}, ${t('room', 'Room')} ${roomIdx + 1}`}
                                >
                                    &minus;
                                </button>
                                <span className="nvt-guest-count" aria-live="polite">{room.adults}</span>
                                <button
                                    type="button"
                                    className="nvt-guest-btn"
                                    disabled={room.adults >= maxAdults}
                                    onClick={() => updateRoom(roomIdx, 'adults', room.adults + 1)}
                                    aria-label={`${t('addAdult', 'Add 1 adult')}, ${t('room', 'Room')} ${roomIdx + 1}`}
                                >
                                    +
                                </button>
                            </div>
                        </div>

                        {/* Children */}
                        <div className="nvt-guest-row">
                            <div className="nvt-guest-label">
                                {t('children', 'Children')}
                                <small>0–17</small>
                            </div>
                            <div className="nvt-guest-controls">
                                <button
                                    type="button"
                                    className="nvt-guest-btn"
                                    disabled={room.children <= 0}
                                    onClick={() => updateRoom(roomIdx, 'children', room.children - 1)}
                                    aria-label={`${t('removeChild', 'Remove 1 child')}, ${t('room', 'Room')} ${roomIdx + 1}`}
                                >
                                    &minus;
                                </button>
                                <span className="nvt-guest-count" aria-live="polite">{room.children}</span>
                                <button
                                    type="button"
                                    className="nvt-guest-btn"
                                    disabled={room.children >= maxChildren}
                                    onClick={() => updateRoom(roomIdx, 'children', room.children + 1)}
                                    aria-label={`${t('addChild', 'Add 1 child')}, ${t('room', 'Room')} ${roomIdx + 1}`}
                                >
                                    +
                                </button>
                            </div>
                        </div>

                        {/* Child ages */}
                        {room.children > 0 && (
                            <div className="nvt-child-ages">
                                <div className="nvt-child-ages-header">
                                    {t('childrenAges', "Children's ages")}
                                </div>
                                <div className="nvt-child-ages-message">
                                    {t('childAge', "Child's age at check-in")}
                                </div>
                                {Array.from({ length: room.children }, (_, childIdx) => {
                                    const age = (room.childrenAges || [])[childIdx];
                                    const error = hasAgeError(roomIdx, childIdx);

                                    return (
                                        <div
                                            key={childIdx}
                                            className={`nvt-child-age-row${error ? ' nvt-age-error' : ''}`}
                                        >
                                            <label>
                                                {t('child', 'Child')} {childIdx + 1}
                                            </label>
                                            <select
                                                className="nvt-child-age-select"
                                                value={age !== null && age !== undefined ? age : ''}
                                                onChange={(e) => {
                                                    const val = e.target.value;
                                                    setChildAge(roomIdx, childIdx, val === '' ? null : parseInt(val));
                                                }}
                                                aria-label={`${t('ageOfChild', 'Age of child')} ${childIdx + 1}, ${t('room', 'Room')} ${roomIdx + 1}`}
                                                aria-invalid={error || undefined}
                                            >
                                                <option value="">
                                                    {t('selectAge', 'Select age')}
                                                </option>
                                                {Array.from({ length: 18 }, (_, a) => (
                                                    <option key={a} value={a}>
                                                        {a} {a === 1
                                                            ? t('yearOld', 'year old')
                                                            : t('yearsOld', 'years old')}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                ))}
            </div>

            {rooms.length < maxRooms && (
                <button
                    type="button"
                    className="nvt-add-room-btn"
                    onClick={addRoom}
                >
                    + {t('addRoom', 'Add Room')}
                </button>
            )}

            <button
                type="button"
                className="nvt-done-btn"
                onClick={handleDone}
            >
                {t('done', 'Done')}
            </button>
        </div>
    );
}

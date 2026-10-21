/**
 * Scheduled send plugin script
 *
 * @licstart  The following is the entire license notice for the
 * JavaScript code in this file.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @licend  The above is the entire license notice
 * for the JavaScript code in this file.
 */

window.rcmail && rcmail.addEventListener('init', function() {
    if (rcmail.env.action == 'compose') {
        scheduled_send_compose_init();
    }
    else if (rcmail.env.action == 'plugin.scheduled_send') {
        scheduled_send_list_init();
    }
});

function scheduled_send_label(name, vars)
{
    var label = rcmail.get_label(name, 'scheduled_send');

    $.each(vars || {}, function(key, value) {
        label = label.replace('$' + key, value);
    });

    return label;
}

function scheduled_send_compose_init()
{
    rcmail.register_command('plugin.scheduled_send', function(props) {
        // Same checks as "Send"; a confirmation (e.g. empty subject) re-runs this command with nocheck
        if (!(props && props.nocheck) && !rcmail.check_compose_input('plugin.scheduled_send')) {
            return;
        }

        scheduled_send_dialog();
    }, true);

    // Otherwise Roundcube treats the command as leaving the compose screen
    // and asks whether to discard the unsaved message.
    rcmail.env.compose_commands.push('plugin.scheduled_send');

    // A plain "Send" must never carry a schedule left over from an earlier attempt
    rcmail.addEventListener('beforesend', function(props) {
        if (!(props && props.scheduled_send)) {
            scheduled_send_clear();
        }
    });

    var label = scheduled_send_label('sendlater'),
        button = $('<button type="button" class="btn btn-secondary scheduled-send">')
            .text(label)
            .attr('title', scheduled_send_label('sendlatertitle'))
            .on('click', function() { rcmail.command('plugin.scheduled_send'); }),
        send = $('.formbuttons .send').first();

    if (send.length) {
        button.insertAfter(send);
    }
    else {
        $('#compose-toolbar, .toolbar').first().append(button);
    }
}

function scheduled_send_timezone()
{
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    }
    catch (e) {
        return 'UTC';
    }
}

// Local date/time in the format of <input type="datetime-local">
function scheduled_send_format(date)
{
    var pad = function(n) { return (n < 10 ? '0' : '') + n; };

    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
        + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
}

function scheduled_send_preset(days, hour, weekday)
{
    var date = new Date();

    if (weekday !== undefined) {
        days = (weekday - date.getDay() + 7) % 7 || 7;
    }

    date.setDate(date.getDate() + days);
    date.setHours(hour, 0, 0, 0);

    return scheduled_send_format(date);
}

function scheduled_send_dialog()
{
    var tz = scheduled_send_timezone(),
        now = new Date(),
        input = $('<input type="datetime-local" id="scheduled-send-at" class="form-control" required>')
            .attr('min', scheduled_send_format(now))
            .val(scheduled_send_preset(1, 8)),
        presets = $('<div class="scheduled-send-presets">'),
        content = $('<div class="scheduled-send-dialog">');

    $.each([
        ['tomorrowmorning', scheduled_send_preset(1, 8)],
        ['tomorrowafternoon', scheduled_send_preset(1, 13)],
        ['mondaymorning', scheduled_send_preset(0, 8, 1)]
    ], function(i, preset) {
        $('<button type="button" class="btn btn-secondary">')
            .text(scheduled_send_label(preset[0]))
            .on('click', function() { input.val(preset[1]).focus(); })
            .appendTo(presets);
    });

    content.append(presets)
        .append($('<label for="scheduled-send-at">').text(scheduled_send_label('dateandtime')))
        .append(input)
        .append($('<p class="hint">').text(scheduled_send_label('timezone', {tz: tz})));

    rcmail.simple_dialog(content, scheduled_send_label('sendlatertitle'), function() {
        var value = input.val();

        if (!value || new Date(value) <= new Date()) {
            rcmail.alert_dialog(scheduled_send_label('dateinpast'));
            return false;
        }

        scheduled_send_submit(value, tz);
        return true;
    }, {button: 'scheduled_send.schedule', button_class: 'send'});
}

function scheduled_send_clear()
{
    $('input[name="_schedule_at"], input[name="_schedule_tz"]', rcmail.gui_objects.messageform).remove();
}

function scheduled_send_submit(value, tz)
{
    scheduled_send_clear();

    // The fields stay in the form until the compose screen closes or a plain "Send"
    // removes them: Mailvelope and Enigma submit the form later (after encryption
    // or a key password prompt), and that submission must still be scheduled.
    $(rcmail.gui_objects.messageform)
        .append($('<input type="hidden" name="_schedule_at">').val(value))
        .append($('<input type="hidden" name="_schedule_tz">').val(tz));

    rcmail.command('send', {nocheck: true, scheduled_send: true});
}

function scheduled_send_list_init()
{
    rcmail.addEventListener('plugin.scheduled_send.refresh', function() {
        location.reload();
    });

    $('#scheduled-send-list').on('click', 'a.btn', function(e) {
        e.preventDefault();

        var id = $(this).data('id'),
            action = $(this).hasClass('cancel') ? 'cancel' : 'restore',
            post = function() {
                rcmail.http_post('plugin.scheduled_send.' + action, {_id: id}, rcmail.set_busy(true, 'loading'));
                return true;
            };

        if (action == 'cancel') {
            rcmail.simple_dialog(scheduled_send_label('confirmcancel'), 'scheduled_send.scheduledmessages', post,
                {button: 'scheduled_send.cancel', button_class: 'delete'});
        }
        else {
            post();
        }
    });
}

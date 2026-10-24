<div class="card">
    <strong>Mail client settings</strong>
    <table>
        <tr><th>Username</th><td>{{ $settings['address'] }}</td></tr>
        <tr><th>Incoming IMAP</th><td>{{ $settings['imap_host'] }} · port {{ $settings['imap_port'] }} · SSL/TLS</td></tr>
        <tr><th>Incoming POP3</th><td>{{ $settings['imap_host'] }} · port {{ $settings['pop_port'] }} · SSL/TLS</td></tr>
        <tr><th>Outgoing SMTP</th><td>{{ $settings['smtp_host'] }} · port {{ $settings['smtp_port'] }} · SSL/TLS (or {{ $settings['smtp_starttls_port'] }} STARTTLS)</td></tr>
        <tr><th>Webmail</th><td><a href="{{ $settings['webmail'] }}" rel="noopener noreferrer" target="_blank">{{ $settings['webmail'] }}</a></td></tr>
    </table>
</div>

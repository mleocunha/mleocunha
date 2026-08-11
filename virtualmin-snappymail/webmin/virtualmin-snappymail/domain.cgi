#!/usr/bin/perl
# Per-domain SnappyMail webapp management page.

require 'virtualmin-snappymail-lib.pl';
use strict;
use warnings;
our (%text, %in);

&ReadParse();
my $dom = &validate_domain_name($in{'dom'});
&require_domain_access($dom);

&ui_print_header(&text('domain_title', $dom), $text{'domain_header'}, "");

my $cerr = &cli_available();
if ($cerr) {
	print &ui_alert_box($cerr, 'danger'), "\n";
	&ui_print_footer('index.cgi', $text{'index_return'});
	exit;
}

my $st = status_for($dom);
my $wm = ($st && $st->{'webmail_domain'}) || "webmail.$dom";
my $ver = $st ? ($st->{'snappymail'} // '-') : '-';
my $installed = ($ver ne '-' && $ver ne '');

print &ui_table_start($text{'domain_status'}, undef, 2);
print &ui_table_row($text{'index_domain'}, &html_escape($dom));
print &ui_table_row($text{'index_webmail'}, &html_escape($wm));
print &ui_table_row($text{'index_version'}, &html_escape($ver));
if ($st) {
	print &ui_table_row($text{'index_https'}, &html_escape($st->{'https'} // '-'));
	print &ui_table_row($text{'index_imap'}, &html_escape($st->{'imap'} // '-'));
	print &ui_table_row($text{'index_smtp'}, &html_escape($st->{'smtp'} // '-'));
	print &ui_table_row($text{'index_mode'}, &html_escape($st->{'mode'} // '-'));
	print &ui_table_row($text{'index_managed'},
		($st->{'managed'} ? $text{'index_yes'} : $text{'index_no'}));
}
print &ui_table_end();

if (!$installed) {
	print &ui_alert_box($text{'domain_missing'}, 'warn'), "\n";
	print &ui_links_row([
		&ui_link('install.cgi?dom='.&urlize($dom), $text{'domain_install'}),
	]);
} else {
	print &ui_links_row([
		&ui_link("https://$wm/", $text{'domain_open'}),
		&ui_link('diagnose.cgi?dom='.&urlize($dom), $text{'domain_diagnose'}),
		&ui_link('repair.cgi?dom='.&urlize($dom), $text{'domain_repair'}),
		&ui_link('upgrade.cgi?dom='.&urlize($dom), $text{'domain_upgrade'}),
		&ui_link('remove_form.cgi?dom='.&urlize($dom), $text{'domain_remove'}),
	]);
}

&ui_print_footer('index.cgi', $text{'index_return'});

#!/usr/bin/perl
# Global SnappyMail webapp manager — status across Virtual Servers.

require 'virtualmin-snappymail-lib.pl';
use strict;
use warnings;
our (%text, %config, $module_name);

&ui_print_header(undef, $text{'index_title'}, "", undef, 1, 1);

my $cerr = &cli_available();
if ($cerr) {
	print &ui_alert_box($cerr, 'warn'), "\n";
	print &text('index_cli_error', &html_escape($cerr)), "<p>\n";
	&ui_print_footer('/', $text{'index'});
	exit;
}

print &ui_subheading($text{'index_global'}), "\n";

my @rows = &list_status_rows();
my @table;
foreach my $r (sort { ($a->{'domain'} // '') cmp ($b->{'domain'} // '') } @rows) {
	my $dom = $r->{'domain'} // next;
	next unless &can_domain($dom);
	my $wm = $r->{'webmail_domain'} // ("webmail.$dom");
	my $ver = $r->{'snappymail'} // '-';
	my $installed = ($ver ne '-' && $ver ne '');
	my $managed = $r->{'managed'} ? $text{'index_yes'} : $text{'index_no'};
	my $actions = &ui_links_row([
		&ui_link('domain.cgi?dom='.&urlize($dom), $text{'index_manage'}),
	]);
	push(@table, [
		&ui_link('domain.cgi?dom='.&urlize($dom), &html_escape($dom)),
		&html_escape($wm),
		$installed ? &html_escape($ver) : "<i>$text{'index_missing'}</i>",
		&html_escape($r->{'https'} // '-'),
		&html_escape($r->{'imap'} // '-'),
		&html_escape($r->{'smtp'} // '-'),
		&html_escape($r->{'mode'} // '-'),
		$managed,
		$actions,
	]);
}

print &ui_columns_table(
	[
		$text{'index_domain'},
		$text{'index_webmail'},
		$text{'index_version'},
		$text{'index_https'},
		$text{'index_imap'},
		$text{'index_smtp'},
		$text{'index_mode'},
		$text{'index_managed'},
		$text{'index_actions'},
	],
	100,
	\@table,
	undef,
	0,
	undef,
	$text{'index_none'},
);

print &ui_links_row([
	&ui_link('install_form.cgi', $text{'index_install'}),
	&ui_link('discover.cgi', $text{'index_discover'}),
	&ui_link('audit.cgi', $text{'index_audit'}),
	&ui_link("../config.cgi?$module_name", $text{'index_config'} || 'Module config'),
]);

&ui_print_footer('/', $text{'index'});

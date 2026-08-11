#!/usr/bin/perl
require 'virtualmin-snappymail-lib.pl';
use strict;
use warnings;
our (%text);

&ui_print_header(undef, $text{'discover_title'}, "");

my $cerr = &cli_available();
&error($cerr) if $cerr;

my @hits = &discover_hits();
my @table;
foreach my $h (@hits) {
	my $parent = $h->{'parent_domain'} // '';
	next if $parent && !&can_domain($parent);
	my $wm = $h->{'webmail_domain'} // '';
	my $managed = $h->{'managed'} ? $text{'index_yes'} : $text{'index_no'};
	my $adopt = (!$h->{'managed'} && $parent)
		? &ui_link('adopt.cgi?dom='.&urlize($parent), $text{'discover_adopt'})
		: '';
	push(@table, [
		&html_escape($wm),
		$parent ? &ui_link('domain.cgi?dom='.&urlize($parent), &html_escape($parent)) : '-',
		&html_escape($h->{'version'} // '-'),
		$managed,
		$adopt,
	]);
}

print &ui_columns_table(
	[
		$text{'discover_webmail'},
		$text{'discover_parent'},
		$text{'discover_version'},
		$text{'discover_managed'},
		$text{'index_actions'},
	],
	100,
	\@table,
	undef,
	0,
	undef,
	$text{'discover_none'},
);

print &ui_links_row([
	&ui_link('adopt.cgi?all=1', $text{'discover_adopt_all'}),
	&ui_link('index.cgi', $text{'index_return'}),
]);

&ui_print_footer('index.cgi', $text{'index_return'});

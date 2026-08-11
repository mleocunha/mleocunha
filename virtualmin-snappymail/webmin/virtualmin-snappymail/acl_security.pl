#!/usr/bin/perl
# ACL options for domain owners granted this module via feature_webmin.
require 'virtualmin-snappymail-lib.pl';
use strict;
use warnings;
our (%text, %in);

sub acl_security_form {
	my ($o) = @_;
	print &ui_table_row($text{'acl_domains'},
		&ui_radio('domains',
			defined($o->{'domains'}) ? $o->{'domains'} : 1,
			[ [ 1, $text{'acl_all'} ],
			  [ 0, $text{'acl_owned'} ] ]));
}

sub acl_security_save {
	my ($o) = @_;
	$o->{'domains'} = $in{'domains'};
}

1;

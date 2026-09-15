import { h } from 'preact';
import { useMemo, useState } from 'preact/hooks';
import { announce, copyText, safeUrl } from '../../shared';
import { getConfig } from '../api/config';

const PROGRESS_KEY = 'plumberslot_docs_quick_start_v1';

const QUICK_START = [
	{
		id: 'profile',
		number: '01',
		title: 'Add your tutor profile',
		text: 'Choose the tutor, timezone and meeting preference students should see.',
		screen: 'plumberslot-technicians',
		action: 'Open tutors',
	},
	{
		id: 'subject',
		number: '02',
		title: 'Create at least one subject',
		text: 'Give it a clear name, lesson length and price. Keep the first offer simple.',
		screen: 'plumberslot-services',
		action: 'Add a subject',
	},
	{
		id: 'availability',
		number: '03',
		title: 'Paint your open hours',
		text: 'Click or drag on the weekly timetable. Add time off for one-off closures.',
		screen: 'plumberslot-availability',
		action: 'Set availability',
	},
	{
		id: 'publish',
		number: '04',
		title: 'Publish the booking page',
		text: 'Add the PlumberSlot block to a page, preview it, then share the page link.',
		screen: 'plumberslot-setup',
		action: 'Open setup',
	},
];

const USP_ITEMS = [
	{
		mark: '01',
		title: 'Tutor-first availability',
		text: 'A visual weekly timetable makes “when can people book me?” easy to answer. Buffers, notice time and one-off closures live beside it.',
	},
	{
		mark: '02',
		title: 'A slot cannot be quietly double-booked',
		text: 'Short booking holds and overlap protection keep two people from confirming the same lesson time.',
	},
	{
		mark: '03',
		title: 'Built for families, not only accounts',
		text: 'Parents can link learners, book for a child, follow upcoming lessons and see tutor notes from one dashboard.',
	},
	{
		mark: '04',
		title: 'Free, paid or prepaid lessons',
		text: 'Start with free or offline payment. Add packages, Stripe or bKash only when your teaching business needs them.',
	},
	{
		mark: '05',
		title: 'The follow-up work is connected',
		text: 'Confirmation, reminders, meeting links, rescheduling, attendance and notes stay attached to the same booking.',
	},
	{
		mark: '06',
		title: 'WordPress ownership stays visible',
		text: 'Role checks, privacy tools, audit records and masked secrets are part of the product—not an afterthought.',
	},
];

const GUIDES = [
	{
		id: 'availability',
		label: 'Availability',
		title: 'Decide when students may book',
		intro: 'Use this before sharing your booking page. It creates the open times students can actually choose.',
		steps: [
			'Open Availability and confirm the timezone shown above the grid.',
			'Click a cell, or drag across several cells, to mark open teaching time.',
			'Set lesson length, buffer time and minimum booking notice.',
			'Use Time off for holidays, appointments or any one-day closure.',
			'Save availability and preview the public booking page.',
		],
		tip: 'Open hours repeat every week. Time off changes only the date you choose.',
		screen: 'plumberslot-availability',
	},
	{
		id: 'subjects',
		label: 'Subjects & pricing',
		title: 'Explain what a student can book',
		intro: 'A subject is the lesson offer: what you teach, how long it runs and what it costs.',
		steps: [
			'Open Subjects and choose the tutor who teaches the lesson.',
			'Use a student-friendly name such as “GCSE Mathematics” instead of an internal code.',
			'Add level, duration and price. A zero price appears as Free.',
			'Enable a trial only when the tutor wants to offer one.',
			'Save, then make sure the subject is active.',
		],
		tip: 'Students only see subjects assigned to the tutor they selected.',
		screen: 'plumberslot-services',
	},
	{
		id: 'booking',
		label: 'Public booking',
		title: 'Let a student choose and confirm a lesson',
		intro: 'The booking flow asks one question at a time: subject, time, learner and confirmation.',
		steps: [
			'Place the PlumberSlot block on a page, or use the shortcode shown below.',
			'Share that page. Visitors may browse subjects and open times before signing in.',
			'The student chooses a subject and an available day and time.',
			'PlumberSlot briefly holds that time while the student reviews the details.',
			'After confirmation, the student sees the booking summary and next actions.',
		],
		tip: 'Times are shown in the visitor’s timezone when that setting is enabled.',
		booking: true,
	},
	{
		id: 'bookings',
		label: 'Manage lessons',
		title: 'Handle a booking after it is made',
		intro: 'Bookings is the tutor’s working list for upcoming and past lessons.',
		steps: [
			'Open Bookings and search by student, subject or booking reference.',
			'Use View to check the learner, payment and meeting details.',
			'Reschedule only to an open time; the old lesson is marked as moved.',
			'Cancel when the lesson will not happen. Scheduled reminders and meetings are cleaned up.',
			'After the lesson, mark Completed or No show and add a short tutor note.',
		],
		tip: 'Status changes are permission-checked and recorded for accountability.',
		screen: 'plumberslot-bookings',
	},
	{
		id: 'family',
		label: 'Students & parents',
		title: 'Keep family bookings understandable',
		intro: 'A parent can connect a learner and book on their behalf without sharing accounts.',
		steps: [
			'The parent opens their dashboard and adds the learner’s WordPress email.',
			'The learner accepts or uses the confirmed relationship created by the site.',
			'During booking, the parent chooses “For my child”.',
			'Upcoming lessons and available package credits appear on the family dashboard.',
			'Tutor notes help the parent understand what was covered after each lesson.',
		],
		tip: 'A parent can only act for a learner with a confirmed relationship.',
	},
	{
		id: 'payments',
		label: 'Credits & payments',
		title: 'Choose how lessons are paid for',
		intro: 'PlumberSlot works without online payment. Turn on only the payment method you intend to use.',
		steps: [
			'For free lessons, leave the subject price at zero.',
			'For manual payment, agree payment outside the site and manage the booking normally.',
			'For packages, create lesson credits that can be spent during confirmation.',
			'For online checkout, configure Stripe or bKash under Settings → Connections.',
			'Run a low-value test booking before sharing a live paid page.',
		],
		tip: 'Credit spending and booking creation happen together, so a failed booking does not silently consume a credit.',
		screen: 'plumberslot-settings',
	},
	{
		id: 'meetings',
		label: 'Meetings & reminders',
		title: 'Deliver the lesson without manual link chasing',
		intro: 'Connect a meeting provider once, then let each confirmed booking carry the correct lesson link.',
		steps: [
			'Open Settings → Connections and choose Google Meet, Zoom or no online meeting.',
			'Complete the provider connection and save the settings.',
			'Confirm a test lesson and check that its meeting information appears.',
			'Keep the 24-hour and 1-hour email reminders enabled when they suit your workflow.',
			'Cancel the test booking and confirm the old meeting is no longer used.',
		],
		tip: 'Join links are signed and time-limited; users still need permission to view their booking.',
		screen: 'plumberslot-settings',
	},
	{
		id: 'privacy',
		label: 'Privacy & safe operation',
		title: 'Know what PlumberSlot stores and shares',
		intro: 'Bookings need names, lesson details and account relationships. Optional services receive data only when configured and used.',
		steps: [
			'Review PlumberSlot’s suggested text under Settings → Privacy.',
			'Document any payment, meeting, email or SMS provider your site enables.',
			'Use WordPress Export Personal Data when a user asks for a copy.',
			'Use Erase Personal Data for a verified erasure request.',
			'Send suspected vulnerabilities privately instead of posting secrets in a public issue.',
		],
		tip: 'API secrets are masked when settings are returned to the browser.',
		screen: 'plumberslot-settings',
	},
];

const FAQS = [
	{
		question: 'Do I need WooCommerce?',
		answer: 'No. Free lessons, manual payment, packages, Stripe and bKash can work without WooCommerce.',
	},
	{
		question: 'Can a visitor book without an account?',
		answer: 'A visitor can browse subjects and open times. They sign in before holding and confirming a lesson so PlumberSlot can protect ownership.',
	},
	{
		question: 'What happens if two students choose the same time?',
		answer: 'PlumberSlot uses a short hold and database overlap protection. Only one valid booking can own the slot.',
	},
	{
		question: 'Can a parent book for more than one child?',
		answer: 'Yes. Each learner needs a confirmed parent relationship, then the parent chooses the learner during booking.',
	},
	{
		question: 'Are online meetings required?',
		answer: 'No. A booking may use Google Meet, Zoom, an offline location or no meeting provider.',
	},
	{
		question: 'Will deactivation delete bookings?',
		answer: 'No. Deactivation removes PlumberSlot scheduled actions but keeps plugin data. Destructive uninstall is a separate, explicit setting.',
	},
];

function readProgress() {
	if ( typeof window === 'undefined' ) {
		return [];
	}

	try {
		const value = JSON.parse( window.localStorage.getItem( PROGRESS_KEY ) );
		return Array.isArray( value ) ? value : [];
	} catch {
		return [];
	}
}

function saveProgress( value ) {
	try {
		window.localStorage.setItem( PROGRESS_KEY, JSON.stringify( value ) );
	} catch {
		// The checklist still works for this page view when storage is blocked.
	}
}

function adminUrl( screen ) {
	const config = getConfig();
	const base = safeUrl( config.urls?.admin, { sameOrigin: true } );
	return base ? `${ base }?page=${ encodeURIComponent( screen ) }` : '#';
}

function ActionLink( { href, children, external = false, primary = false } ) {
	return h(
		'a',
		{
			class: `ts-btn ${
				primary ? 'ts-btn--primary' : 'ts-btn--secondary'
			} ts-docs__action`,
			href,
			target: external ? '_blank' : undefined,
			rel: external ? 'noopener noreferrer' : undefined,
		},
		children
	);
}

export function HelpDocsScreen() {
	const config = getConfig();
	const [ query, setQuery ] = useState( '' );
	const [ progress, setProgress ] = useState( readProgress );
	const [ copied, setCopied ] = useState( false );
	const normalizedQuery = query.trim().toLowerCase();
	const filteredGuides = useMemo(
		() =>
			GUIDES.filter( ( guide ) =>
				[
					guide.label,
					guide.title,
					guide.intro,
					guide.tip,
					...guide.steps,
				]
					.join( ' ' )
					.toLowerCase()
					.includes( normalizedQuery )
			),
		[ normalizedQuery ]
	);
	const completed = QUICK_START.filter( ( step ) =>
		progress.includes( step.id )
	).length;
	const percent = Math.round( ( completed / QUICK_START.length ) * 100 );
	const docs = config.docs || {};
	const videoUrl = safeUrl( docs.videoUrl );
	const bookingUrl = safeUrl( config.urls?.bookingPage, {
		sameOrigin: true,
	} );

	const toggleStep = ( id ) => {
		const next = progress.includes( id )
			? progress.filter( ( item ) => item !== id )
			: [ ...progress, id ];
		setProgress( next );
		saveProgress( next );
		announce(
			`${ next.length } of ${ QUICK_START.length } setup steps complete.`
		);
	};

	const copyShortcode = async () => {
		await copyText( '[plumberslot tutor="your-tutor-slug"]' );
		setCopied( true );
		announce( 'Shortcode copied.' );
		window.setTimeout( () => setCopied( false ), 1800 );
	};

	return h(
		'main',
		{ class: 'ts-docs', id: 'ts-docs-top' },
		h(
			'section',
			{ class: 'ts-docs__hero', 'aria-labelledby': 'ts-docs-title' },
			h(
				'div',
				{ class: 'ts-docs__hero-copy' },
				h( 'p', { class: 'ts-docs__eyebrow' }, 'PLUMBERSLOT GUIDE' ),
				h(
					'h1',
					{ id: 'ts-docs-title' },
					'Teaching time, without scheduling chaos.'
				),
				h(
					'p',
					{ class: 'ts-docs__lead' },
					'PlumberSlot is a WordPress lesson-booking workspace for tutors, students and parents. You decide what you teach and when you are free. Students choose a real open time, confirm the lesson, and receive the right reminders and meeting details.'
				),
				h(
					'p',
					{ class: 'ts-docs__plain' },
					'You do not need to understand calendars, APIs or databases. Start with the four steps below; add payments and online meetings later only if you need them.'
				),
				h(
					'div',
					{ class: 'ts-docs__hero-actions' },
					h(
						ActionLink,
						{ href: '#ts-docs-start', primary: true },
						'Start the 4-step setup'
					),
					bookingUrl
						? h(
								ActionLink,
								{ href: bookingUrl },
								'Preview booking page'
						  )
						: null
				)
			),
			h(
				'div',
				{ class: 'ts-docs__hero-note' },
				h( 'span', { 'aria-hidden': 'true' }, '✓' ),
				h( 'strong', null, 'The simple version' ),
				h(
					'p',
					null,
					'Open hours + a subject + a booking page = ready for the first student.'
				)
			)
		),
		h(
			'nav',
			{ class: 'ts-docs__nav', 'aria-label': 'Documentation sections' },
			[
				[ '#ts-docs-video', 'Quick tour' ],
				[ '#ts-docs-why', 'Why PlumberSlot' ],
				[ '#ts-docs-start', 'Get started' ],
				[ '#ts-docs-guides', 'Feature guides' ],
				[ '#ts-docs-faq', 'FAQ' ],
				[ '#ts-docs-help', 'Get help' ],
			].map( ( item ) =>
				h( 'a', { href: item[ 0 ], key: item[ 0 ] }, item[ 1 ] )
			)
		),
		h(
			'section',
			{
				class: 'ts-docs__section ts-docs__video-section',
				id: 'ts-docs-video',
			},
			h(
				'div',
				{ class: 'ts-docs__section-heading' },
				h( 'p', { class: 'ts-docs__kicker' }, 'QUICK VIDEO TOUR' ),
				h( 'h2', null, 'See the whole journey once' ),
				h(
					'p',
					null,
					'The short tour opens on YouTube, Vimeo or another trusted video platform. Nothing is streamed from this plugin.'
				)
			),
			h(
				'article',
				{ class: 'ts-docs__video-link' },
				h( 'span', { 'aria-hidden': 'true' }, '▶' ),
				h(
					'div',
					null,
					h( 'strong', null, 'PlumberSlot product tour' ),
					h(
						'p',
						null,
						videoUrl
							? 'Watch the latest walkthrough on the video platform.'
							: 'The written walkthrough below is ready now. The public video link will appear here when it is published.'
					)
				),
				videoUrl
					? h(
							ActionLink,
							{ href: videoUrl, external: true, primary: true },
							'Watch video ↗'
					  )
					: h( 'em', null, 'Video link coming soon' )
			),
			h(
				'details',
				{ class: 'ts-docs__transcript' },
				h( 'summary', null, 'Read the video outline' ),
				h(
					'ol',
					null,
					[
						'PlumberSlot turns your real teaching hours into bookable lesson times.',
						'Create a subject with a clear name, duration and price.',
						'Paint weekly availability and add one-off time away.',
						'Students choose a subject and an open time, then review the lesson before confirming.',
						'Tutors manage rescheduling, cancellation, attendance, meetings and notes from Bookings.',
						'Parents can follow family lessons and lesson credits from one dashboard.',
					].map( ( line ) => h( 'li', { key: line }, line ) )
				)
			)
		),
		h(
			'section',
			{ class: 'ts-docs__section', id: 'ts-docs-why' },
			h(
				'div',
				{ class: 'ts-docs__section-heading' },
				h(
					'p',
					{ class: 'ts-docs__kicker' },
					'WHY IT FEELS DIFFERENT'
				),
				h(
					'h2',
					null,
					'One lesson record, from open time to tutor note'
				),
				h(
					'p',
					null,
					'PlumberSlot does more than place an appointment on a calendar. The people, payment, meeting and follow-up stay connected.'
				)
			),
			h(
				'div',
				{ class: 'ts-docs__usp-grid' },
				USP_ITEMS.map( ( item ) =>
					h(
						'article',
						{ class: 'ts-docs__usp', key: item.mark },
						h( 'span', null, item.mark ),
						h( 'h3', null, item.title ),
						h( 'p', null, item.text )
					)
				)
			)
		),
		h(
			'section',
			{ class: 'ts-docs__section', id: 'ts-docs-start' },
			h(
				'div',
				{
					class: 'ts-docs__section-heading ts-docs__section-heading--progress',
				},
				h(
					'div',
					null,
					h( 'p', { class: 'ts-docs__kicker' }, 'GET BOOKABLE' ),
					h( 'h2', null, 'Your first four steps' ),
					h(
						'p',
						null,
						'Mark a step complete when it is done. Progress is saved in this browser.'
					)
				),
				h(
					'div',
					{
						class: 'ts-docs__progress',
						'aria-label': `${ percent }% complete`,
					},
					h(
						'strong',
						null,
						`${ completed } / ${ QUICK_START.length }`
					),
					h( 'span', null, 'steps complete' ),
					h(
						'div',
						null,
						h( 'i', { style: { width: `${ percent }%` } } )
					)
				)
			),
			h(
				'div',
				{ class: 'ts-docs__steps' },
				QUICK_START.map( ( step ) => {
					const checked = progress.includes( step.id );
					return h(
						'article',
						{
							class: `ts-docs__step ${
								checked ? 'is-complete' : ''
							}`,
							key: step.id,
						},
						h(
							'button',
							{
								class: 'ts-docs__check',
								type: 'button',
								role: 'checkbox',
								'aria-checked': checked,
								'aria-label': `Mark ${ step.title } ${
									checked ? 'incomplete' : 'complete'
								}`,
								onClick: () => toggleStep( step.id ),
							},
							checked ? '✓' : step.number
						),
						h(
							'div',
							null,
							h( 'h3', null, step.title ),
							h( 'p', null, step.text )
						),
						h(
							ActionLink,
							{ href: adminUrl( step.screen ) },
							step.action
						)
					);
				} )
			)
		),
		h(
			'section',
			{ class: 'ts-docs__section', id: 'ts-docs-guides' },
			h(
				'div',
				{ class: 'ts-docs__guide-head' },
				h(
					'div',
					null,
					h( 'p', { class: 'ts-docs__kicker' }, 'USE THE FEATURES' ),
					h( 'h2', null, 'Plain-English guides' ),
					h(
						'p',
						null,
						'Open only the topic you need. Each guide explains what it is for and what to do next.'
					)
				),
				h(
					'label',
					{ class: 'ts-docs__search' },
					h( 'span', null, 'Search the guides' ),
					h( 'input', {
						type: 'search',
						value: query,
						placeholder: 'Try “payments” or “reschedule”',
						onInput: ( event ) =>
							setQuery( event.currentTarget.value ),
					} )
				)
			),
			filteredGuides.length
				? h(
						'div',
						{ class: 'ts-docs__guides' },
						filteredGuides.map( ( guide ) =>
							h(
								'details',
								{
									class: 'ts-docs__guide',
									key: guide.id,
									open: normalizedQuery ? true : undefined,
								},
								h(
									'summary',
									null,
									h( 'span', null, guide.label ),
									h( 'strong', null, guide.title ),
									h( 'i', { 'aria-hidden': 'true' }, '+' )
								),
								h(
									'div',
									{ class: 'ts-docs__guide-body' },
									h(
										'p',
										{ class: 'ts-docs__guide-intro' },
										guide.intro
									),
									h(
										'ol',
										null,
										guide.steps.map( ( step ) =>
											h( 'li', { key: step }, step )
										)
									),
									h(
										'p',
										{ class: 'ts-docs__tip' },
										h( 'strong', null, 'Good to know: ' ),
										guide.tip
									),
									h(
										'div',
										{ class: 'ts-docs__guide-actions' },
										guide.screen
											? h(
													ActionLink,
													{
														href: adminUrl(
															guide.screen
														),
														primary: true,
													},
													`Open ${ guide.label }`
											  )
											: null,
										guide.booking && bookingUrl
											? h(
													ActionLink,
													{ href: bookingUrl },
													'Preview booking page'
											  )
											: null
									)
								)
							)
						)
				  )
				: h(
						'div',
						{ class: 'ts-docs__no-results', role: 'status' },
						h( 'strong', null, 'No guide matched that search.' ),
						h(
							'p',
							null,
							'Try a shorter word, such as “time”, “parent”, “meeting” or “pay”.'
						)
				  ),
			h(
				'div',
				{ class: 'ts-docs__shortcode' },
				h(
					'div',
					null,
					h( 'strong', null, 'Prefer a shortcode?' ),
					h(
						'p',
						null,
						'Replace the example slug with the tutor slug from Tutors.'
					)
				),
				h( 'code', null, '[plumberslot tutor="your-tutor-slug"]' ),
				h(
					'button',
					{
						type: 'button',
						class: 'ts-btn ts-btn--secondary',
						onClick: copyShortcode,
					},
					copied ? 'Copied' : 'Copy shortcode'
				)
			)
		),
		h(
			'section',
			{ class: 'ts-docs__section', id: 'ts-docs-faq' },
			h(
				'div',
				{ class: 'ts-docs__section-heading' },
				h( 'p', { class: 'ts-docs__kicker' }, 'COMMON QUESTIONS' ),
				h( 'h2', null, 'Answers before you need support' )
			),
			h(
				'div',
				{ class: 'ts-docs__faq-grid' },
				FAQS.map( ( item ) =>
					h(
						'details',
						{ class: 'ts-docs__faq', key: item.question },
						h(
							'summary',
							null,
							item.question,
							h( 'span', { 'aria-hidden': 'true' }, '+' )
						),
						h( 'p', null, item.answer )
					)
				)
			)
		),
		h(
			'section',
			{ class: 'ts-docs__help', id: 'ts-docs-help' },
			h(
				'div',
				null,
				h(
					'p',
					{ class: 'ts-docs__kicker' },
					'WHEN SOMETHING DOES NOT LOOK RIGHT'
				),
				h( 'h2', null, 'Start with three calm checks' ),
				h(
					'ol',
					null,
					h( 'li', null, 'Confirm the tutor has an active subject.' ),
					h(
						'li',
						null,
						'Confirm the timetable has open hours in the correct timezone.'
					),
					h(
						'li',
						null,
						'Open the booking page in a private window and test the same steps as a student.'
					)
				)
			),
			h(
				'div',
				{ class: 'ts-docs__help-actions' },
				h(
					ActionLink,
					{ href: adminUrl( 'plumberslot-settings' ), primary: true },
					'Review settings'
				),
				h(
					ActionLink,
					{ href: 'https://github.com/itsmanzur/plumberslot/issues' },
					'Report a non-sensitive bug'
				),
				h(
					'p',
					null,
					'Security issue? Email ',
					h(
						'a',
						{ href: 'mailto:security@plumberslot.com' },
						'security@plumberslot.com'
					),
					' privately.'
				)
			)
		),
		h(
			'a',
			{ class: 'ts-docs__back-top', href: '#ts-docs-top' },
			'Back to top ↑'
		)
	);
}

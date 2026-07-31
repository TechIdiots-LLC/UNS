// UNS background service worker (Manifest V3).
//
// Polls the UNS XML feed and updates the active tab's URL. Manifest V3 service
// workers get unloaded after ~30s idle, so a plain setInterval() (the old
// Manifest V2 approach) stops firing once that happens - chrome.alarms is the
// only thing Chrome guarantees will wake the worker back up on schedule.
// Alarms can't fire more than once a minute, so the server-provided refresh
// interval is clamped to that floor; if you need faster-than-a-minute
// updates, this extension isn't the right mechanism for it.

const ALARM_NAME = "uns-check";
const alertxml = "http://your.uns.server/uns/index.php?id=4c6a8a77381e6e6f41a536ef50891279&out=xml";

chrome.runtime.onInstalled.addListener(() => scheduleAlarm(1));
chrome.runtime.onStartup.addListener(() => scheduleAlarm(1));

chrome.alarms.onAlarm.addListener((alarm) => {
	if (alarm.name === ALARM_NAME) {
		checkFeed();
	}
});

function scheduleAlarm(periodInMinutes) {
	chrome.alarms.create(ALARM_NAME, { periodInMinutes: Math.max(1, periodInMinutes) });
}

function checkFeed() {
	chrome.tabs.query({ active: true, currentWindow: true }, (tabs) => {
		const tab = tabs[0];
		if (!tab || tab.url === "chrome://extensions/") { return; }
		loadPage(tab.id);
	});
}

function loadPage(tabId) {
	fetch(alertxml)
		.then((response) => response.text())
		.then((text) => {
			// Parsed with a plain regex rather than DOMParser - service workers have
			// no DOM, and the feed's shape is fixed and simple enough not to need one.
			const urlMatch = text.match(/<url>(?:<!\[CDATA\[)?([\s\S]*?)(?:\]\]>)?<\/url>/);
			const refreshMatch = text.match(/<refresh>([\s\S]*?)<\/refresh>/);
			const unsurl = urlMatch && urlMatch[1].trim();
			const unsrefresh = refreshMatch && Number(refreshMatch[1]);

			if (unsurl) {
				chrome.tabs.update(tabId, { url: unsurl });
			}
			if (unsrefresh) {
				scheduleAlarm(Math.ceil(unsrefresh / 60));
			}
		})
		.catch(() => { /* network hiccup - the next alarm tick will retry */ });
}

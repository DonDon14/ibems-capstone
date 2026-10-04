// Fail closed when a navigation reaches an error, an unexpected destination,
// missing workflow controls, or a JavaScript exception.
function normalizedPath(value) {
  return value.split('?')[0].replace(/^\/index\.php(?=\/)/, '').replace(/\/$/, '') || '/';
}
function pageFailures(state, { expectedPath, requiredControls = [], exceptions = [] }) {
  const failures = [];
  if (/^(403|404|500)\b/.test(state.title) || /Whoops|ErrorException|DatabaseException|403 -|404 -|Access Denied/.test(state.text)) failures.push('error page');
  if (/login$/.test(state.url)) failures.push('unexpected login');
  if (normalizedPath(state.url) !== normalizedPath(expectedPath)) failures.push('unexpected destination');
  for (const selector of requiredControls) if (!state.controls?.[selector]) failures.push('missing control ' + selector);
  if (exceptions.length) failures.push('JavaScript exception');
  return failures;
}
module.exports = { normalizedPath, pageFailures };

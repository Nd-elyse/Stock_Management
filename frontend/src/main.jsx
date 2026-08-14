import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.min.css';
import * as bootstrap from 'bootstrap';
import './index.css';
import App from './App';

// Bundled locally (not loaded from a CDN) so modals/dropdowns/collapses work
// even on networks that can't reach an external CDN. Existing code calls
// window.bootstrap.Modal/.../getOrCreateInstance(...), so expose it the same way.
window.bootstrap = bootstrap;

const root = ReactDOM.createRoot(document.getElementById('root'));
root.render(
  <React.StrictMode>
    <BrowserRouter future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>
      <App />
    </BrowserRouter>
  </React.StrictMode>
);

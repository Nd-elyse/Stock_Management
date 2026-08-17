import React from 'react';
import { Routes, Route } from 'react-router-dom';
import './App.css';
import './assets/style.css';

import { AuthProvider } from './context';
import { ToastProvider } from './context';
import { PublicLayout, ProtectedRoute } from './components';

import Home from './pages/Home/Home';
import About from './pages/About/About';
import Contact from './pages/Contact/Contact';
import TrackRepair from './pages/TrackRepair/TrackRepair';
import ViewRepairStatusModal from './pages/TrackRepair/ViewRepairStatusModal';
import Login from './pages/Login/Login';
import Admin from './pages/Dashboard/Admin';
import Receptionist from './pages/Dashboard/Receptionist';
import Mechanic from './pages/Dashboard/Mechanic';
import StockManager from './pages/Dashboard/StockManager';

// The "View Repair Status" modal is triggered from the Home hero and the
// Footer, both present on every public page, so mount it alongside
// PublicLayout on all of them rather than tying it to one route.
function PublicPage({ children }) {
  return <PublicLayout modals={<ViewRepairStatusModal />}>{children}</PublicLayout>;
}

function App() {
  return (
    <AuthProvider>
      <ToastProvider>
        <Routes>
          <Route path="/" element={<PublicPage><Home /></PublicPage>} />
          <Route path="/about" element={<PublicPage><About /></PublicPage>} />
          <Route path="/contact" element={<PublicPage><Contact /></PublicPage>} />
          <Route path="/track-repair" element={<PublicPage><TrackRepair /></PublicPage>} />
          <Route path="/login" element={<Login />} />

          <Route path="/dashboard/admin" element={<ProtectedRoute role="Admin"><Admin /></ProtectedRoute>} />
          <Route path="/dashboard/receptionist" element={<ProtectedRoute role="Receptionist"><Receptionist /></ProtectedRoute>} />
          <Route path="/dashboard/mechanic" element={<ProtectedRoute role="Mechanic"><Mechanic /></ProtectedRoute>} />
          <Route path="/dashboard/stock" element={<ProtectedRoute role="Stock Manager"><StockManager /></ProtectedRoute>} />

          <Route path="*" element={<PublicPage><Home /></PublicPage>} />
        </Routes>
      </ToastProvider>
    </AuthProvider>
  );
}

export default App;
